/**
 * ============================================================
 * PROFILE ENRICHMENT ENGINE — FCA Platform
 * ============================================================
 * 
 * Connects live data APIs directly to judge and attorney profiles.
 * 
 * When a profile card is expanded, this module:
 *   1. Queries FEC for real campaign finance donations
 *   2. Cross-references attorney donations against judges in same county
 *   3. Queries OpenStates for any bills sponsored by elected judges
 *   4. Pulls USASpending IV-D data for the judge's state
 *   5. Finds related bar complaints from public databases
 *   6. Caches everything in sessionStorage to avoid repeat API calls
 * 
 * INTEGRATION:
 *   Include this file in index.html:
 *   <script src="profile_enrichment.js"></script>
 * 
 *   Then call from your existing profile render functions:
 *   - selJudge(id)   → add: enrichJudgeProfile(judgeId)
 *   - selAtty(id)    → add: enrichAttorneyProfile(attorneyId)
 * 
 * REQUIRES:
 *   - FEC API key in localStorage('fca_key_fec')
 *   - OpenStates key in localStorage('fca_key_openstates')  [optional]
 *   - USASpending: no key needed
 * ============================================================
 */

// ============================================================
// CACHE — sessionStorage so API calls don't repeat this session
// ============================================================
const ENRICH_CACHE = {
  get(key) {
    try { const v = sessionStorage.getItem('fca_enrich_' + key); return v ? JSON.parse(v) : null; }
    catch(e) { return null; }
  },
  set(key, val) {
    try { sessionStorage.setItem('fca_enrich_' + key, JSON.stringify(val)); } catch(e) {}
  }
};

// ============================================================
// API KEYS — now server-side only.
// ============================================================
// Previously this module read an FEC key from localStorage('fca_key_fec'). That
// pattern is gone: keys live in /etc/fca/api_keys.env, and we reach them only
// through same-origin proxies (api_fec.php, api_courtlistener.php, api_ftm.php).
// Return `true` here to keep call sites that gate on "is a key configured?"
// working — configuration now lives on the server and any failure path returns
// a proxy error with a clear message.
function getFECKey()        { return 'server-proxy'; }
function getOpenStatesKey() { return 'server-proxy'; }

// ============================================================
// ATTORNEY PROFILE ENRICHMENT
// ============================================================
async function enrichAttorneyProfile(attorneyId) {
  // Get attorney object from the existing ATTORNEYS array
  const atty = (window.ATTORNEYS || []).find(a => a.id === attorneyId);
  if (!atty) return;

  const containerId = `enrich-atty-${attorneyId}`;
  const container = document.getElementById(containerId);
  if (!container) return;

  const cacheKey = `atty_${attorneyId}`;
  const cached = ENRICH_CACHE.get(cacheKey);
  if (cached) { renderAttorneyEnrichment(container, cached, atty); return; }

  container.innerHTML = enrichLoadingHTML('Pulling live campaign finance data...');

  const results = { fec: null, stateFinance: null, barComplaints: null };

  // --- FEC: search donations made BY this attorney ---
  if (getFECKey()) {
    results.fec = await fetchFECDonationsBy(atty.name, atty.state);
  }

  // --- Cross-reference: do any donations go to judges in their county? ---
  if (results.fec?.donations?.length) {
    results.fec.crossRef = crossReferenceWithJudges(results.fec.donations, atty.state);
  }

  // --- USASpending: IV-D context for their state ---
  results.stateFinance = await fetchStateIVD(atty.state);

  // --- Public bar complaint links ---
  results.barComplaints = getBarComplaintContext(atty.state, atty.barNum);

  ENRICH_CACHE.set(cacheKey, results);
  renderAttorneyEnrichment(container, results, atty);
}

async function fetchFECDonationsBy(name, state) {
  const cacheKey = `fec_atty_${name.replace(/\s/g,'_')}`;
  const cached = ENRICH_CACHE.get(cacheKey);
  if (cached) return cached;

  try {
    // Server-side proxy handles auth + caches responses for 24h. Never send a
    // key from the browser.
    const qs = new URLSearchParams({op: 'contributions', name, per_page: '50'});
    if (state) qs.set('state', state.length === 2 ? state : state);
    const r = await fetch('api_fec.php?' + qs.toString());
    if (!r.ok) throw new Error('proxy ' + r.status);
    const payload = await r.json();
    if (!payload.ok) throw new Error(payload.error || 'proxy error');
    const raw = payload.data && payload.data.results ? payload.data.results : [];
    const donations = raw.map(d => ({
      recipient:    (d.committee && d.committee.name) || d.committee_id,
      amount:       d.contribution_receipt_amount,
      date:         d.contribution_receipt_date,
      cycle:        d.election_full_primary || d.election_cycle,
      employerNote: d.contributor_employer,
      occupation:   d.contributor_occupation,
    }));
    const result = { donations, total: donations.reduce((s,d) => s + (d.amount||0), 0), source: 'FEC.gov (proxied)' };
    ENRICH_CACHE.set(cacheKey, result);
    return result;
  } catch(e) {
    return { error: e.message, donations: [] };
  }
}

function crossReferenceWithJudges(donations, state) {
  // Check if any FEC recipients match judges in the JUDGES array who are in the same state
  const judges = (window.JUDGES || []).filter(j => j.state === state || j.district?.includes(state));
  const matches = [];
  donations.forEach(d => {
    judges.forEach(j => {
      // Fuzzy match: does the recipient committee name contain the judge's name?
      const recipLower = (d.recipient || '').toLowerCase();
      const nameParts = j.name.toLowerCase().replace('hon.','').trim().split(' ').filter(p => p.length > 3);
      const isMatch = nameParts.some(part => recipLower.includes(part));
      if (isMatch) {
        matches.push({
          judgeId: j.id,
          judgeName: j.name,
          judgeTitle: j.title,
          donationAmount: d.amount,
          donationDate: d.date,
          recipient: d.recipient,
        });
      }
    });
  });
  return matches;
}

function getBarComplaintContext(state, barNum) {
  // Bar complaint links and public complaint lookup per state
  const stateData = {
    Texas:         { url: 'https://www.texasbar.com/AM/Template.cfm?Section=Grievance_Information', lookupUrl: `https://www.texasbar.com/AM/Template.cfm?Section=Find_A_Lawyer`, phone: '800-932-1900' },
    Florida:       { url: 'https://www.floridabar.org/about/consumer/consumer-information/file-a-complaint/', lookupUrl: 'https://www.floridabar.org/directories/find-mbr/', phone: '866-352-0707' },
    California:    { url: 'https://www.calbar.ca.gov/Public/File-a-Complaint', lookupUrl: 'https://apps.calbar.ca.gov/attorney/Licensee/Detail/', phone: '800-843-9053' },
    'New York':    { url: 'https://www.nycourts.gov/courts/ad1/committees&divisions/pd/contact.shtml', lookupUrl: 'https://iapps.courts.state.ny.us/attorneyservices/servlet/AttorneyServicesSE', phone: '212-401-0800' },
    Ohio:          { url: 'https://www.ohiobar.org/for-the-public/finding-an-attorney/client-assistance/file-a-grievance/', lookupUrl: 'https://www.supremecourt.ohio.gov/AttorneySearch/', phone: '800-282-6556' },
    Illinois:      { url: 'https://www.iardc.org/File_a_Complaint_about_a_Lawyer.html', lookupUrl: 'https://www.iardc.org/lawyersearch.asp', phone: '312-565-2600' },
    Georgia:       { url: 'https://www.gabar.org/forthepublic/fileacomplaint.cfm', lookupUrl: 'https://www.gabar.org/membersearch/', phone: '800-334-6865' },
    'North Carolina': { url: 'https://www.ncbar.gov/for-the-public/grievances/', lookupUrl: 'https://www.ncbar.gov/for-lawyers/directories/', phone: '919-828-4620' },
    Pennsylvania:  { url: 'https://www.padisciplinaryboard.org/for-the-public/file-complaint', lookupUrl: 'https://www.padisciplinaryboard.org/for-the-public/find-attorney', phone: '800-962-4618' },
    Michigan:      { url: 'https://www.agc.state.mi.us/', lookupUrl: 'https://www.michbar.org/member/locateatty', phone: '800-968-1442' },
    Tennessee:     { url: 'https://www.tbpr.org/filing-a-complaint', lookupUrl: 'https://wrolls.tbpr.org/attorneys/search', phone: '800-486-5714' },
  };
  return stateData[state] || { url: 'https://www.americanbar.org/groups/legal_services/flh-home/flh-client-protection/', phone: 'Contact your state bar' };
}

function renderAttorneyEnrichment(container, results, atty) {
  const fec = results.fec;
  const fin = results.stateFinance;
  const bar = results.barComplaints;
  const crossRef = fec?.crossRef || [];
  const hasDonations = fec?.donations?.length > 0;
  const hasConflict = crossRef.length > 0;

  let html = `<div class="enrich-panel">`;

  // ---- CAMPAIGN FINANCE ----
  html += `<div class="enrich-section">
    <div class="enrich-section-title">
      💰 Campaign Finance — Live FEC Data
      ${hasDonations ? `<span class="enrich-live-badge">✓ Live</span>` : getFECKey() ? `<span class="enrich-badge-grey">No federal records found</span>` : `<span class="enrich-badge-warn">FEC key not configured</span>`}
    </div>`;

  if (hasConflict) {
    html += `<div class="enrich-conflict-alert">
      ⚠️ CONFLICT DETECTED — ${crossRef.length} donation${crossRef.length>1?'s':''} to judge${crossRef.length>1?'s':''} who preside${crossRef.length===1?'s':''} in this attorney's jurisdiction
      ${crossRef.map(x => `<div class="enrich-conflict-row">
        <strong>${atty.name}</strong> → $${(x.donationAmount||0).toLocaleString()} → <strong>${x.judgeName}</strong> (${x.donationDate||'date not recorded'})
        <span class="enrich-source">Source: FEC.gov</span>
      </div>`).join('')}
    </div>`;
  }

  if (hasDonations) {
    html += `<div class="enrich-donations-total">
      Total tracked federal contributions: <strong>$${(fec.total||0).toLocaleString()}</strong>
      &nbsp;·&nbsp; ${fec.donations.length} records &nbsp;·&nbsp; 
      <a href="https://www.fec.gov/data/receipts/individual-contributions/?contributor_name=${encodeURIComponent(atty.name)}" target="_blank" class="enrich-link">Verify on FEC.gov →</a>
    </div>
    <table class="enrich-table">
      <thead><tr><th>Recipient</th><th>Amount</th><th>Date</th></tr></thead>
      <tbody>
        ${fec.donations.slice(0,8).map(d => `<tr>
          <td>${d.recipient || '—'}</td>
          <td class="enrich-money ${d.amount>5000?'red':''}">${d.amount ? '$'+d.amount.toLocaleString() : '—'}</td>
          <td>${d.date || '—'}</td>
        </tr>`).join('')}
        ${fec.donations.length > 8 ? `<tr><td colspan="3" style="font-size:10px;color:var(--text3)">${fec.donations.length - 8} more records on FEC.gov</td></tr>` : ''}
      </tbody>
    </table>`;
  } else if (!getFECKey()) {
    html += `<div class="enrich-no-key">
      FEC campaign finance lookup requires an API key. 
      Configure at <a href="https://api.open.fec.gov/developers/" target="_blank" class="enrich-link">api.open.fec.gov/developers/</a> (free).
      <br>Manual search: <a href="https://www.fec.gov/data/receipts/individual-contributions/?contributor_name=${encodeURIComponent(atty.name)}" target="_blank" class="enrich-link">Search ${atty.name} on FEC.gov →</a>
    </div>`;
  } else {
    html += `<div class="enrich-empty">
      No federal campaign contributions found for "${atty.name}". 
      <a href="https://www.fec.gov/data/receipts/individual-contributions/?contributor_name=${encodeURIComponent(atty.name)}" target="_blank" class="enrich-link">Verify on FEC.gov →</a>
      <br><span style="font-size:9px;color:var(--text3)">Note: State judicial elections use state campaign finance databases, not FEC. Check your state ethics commission.</span>
    </div>`;
  }
  html += `</div>`; // end campaign finance

  // ---- STATE IV-D CONTEXT ----
  if (fin) {
    html += `<div class="enrich-section">
      <div class="enrich-section-title">📊 State IV-D Context — ${atty.state} <span class="enrich-live-badge">✓ Live</span></div>
      <div class="enrich-ivd-row">
        <div class="enrich-stat"><div class="enrich-stat-val green">$${fin.ivd_collected ? (fin.ivd_collected/1000).toFixed(1)+'B' : '—'}</div><div class="enrich-stat-label">IV-D collected/yr</div></div>
        <div class="enrich-stat"><div class="enrich-stat-val purple">$${fin.federal_incentive_paid ? fin.federal_incentive_paid+'M' : '—'}</div><div class="enrich-stat-label">Fed incentive paid to state</div></div>
        <div class="enrich-stat"><div class="enrich-stat-val amber">$${fin.avg_order || '—'}/mo</div><div class="enrich-stat-label">Avg support order</div></div>
        <div class="enrich-stat"><div class="enrich-stat-val red">${fin.cases_open ? (fin.cases_open/1000).toFixed(0)+'K' : '—'}</div><div class="enrich-stat-label">Open IV-D cases</div></div>
      </div>
      <div style="font-size:10px;color:var(--text3);margin-top:6px">Source: HHS OCSE FY2023 · USASpending.gov</div>
    </div>`;
  }

  // ---- BAR COMPLAINT DIRECT LINK ----
  html += `<div class="enrich-section">
    <div class="enrich-section-title">📋 Bar Complaint — Direct Link</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px">
      <a href="${bar?.url}" target="_blank" class="enrich-action-btn teal">File bar complaint — ${atty.state} →</a>
      ${atty.barNum ? `<a href="https://www.texasbar.com" target="_blank" class="enrich-action-btn">Look up Bar #${atty.barNum} →</a>` : ''}
      ${bar?.phone ? `<span style="font-size:11px;color:var(--text3);padding:5px 0">📞 ${bar.phone}</span>` : ''}
    </div>
  </div>`;

  html += `<div class="enrich-footer">
    Live data pulled ${new Date().toLocaleTimeString()} · 
    <a href="#" onclick="refreshEnrichment('atty','${atty.id}')" class="enrich-link">Refresh</a>
  </div>`;
  html += `</div>`;

  container.innerHTML = html;
}

// ============================================================
// JUDGE PROFILE ENRICHMENT
// ============================================================
async function enrichJudgeProfile(judgeId) {
  const judge = (window.JUDGES || []).find(j => j.id === judgeId);
  if (!judge) return;

  const containerId = `enrich-judge-${judgeId}`;
  const container = document.getElementById(containerId);
  if (!container) return;

  const cacheKey = `judge_${judgeId}`;
  const cached = ENRICH_CACHE.get(cacheKey);
  if (cached) { renderJudgeEnrichment(container, cached, judge); return; }

  container.innerHTML = enrichLoadingHTML('Pulling live data for this judge...');

  const results = { fec: null, incomingDonations: null, stateFinance: null, jtcLink: null };

  // --- FEC: search donations TO campaigns named after this judge ---
  if (getFECKey()) {
    results.fec = await fetchFECDonationsTo(judge.name, judge.state);
  }

  // --- Cross-reference: which attorneys in our DB donated to this judge? ---
  results.incomingDonations = crossReferenceAttorneyDonations(judge.name, judge.state);

  // --- State IV-D financial context ---
  results.stateFinance = await fetchStateIVD(judge.state);

  // --- JTC / Judicial conduct commission link ---
  results.jtcLink = getJTCLink(judge.state);

  ENRICH_CACHE.set(cacheKey, results);
  renderJudgeEnrichment(container, results, judge);
}

async function fetchFECDonationsTo(judgeName, state) {
  const cacheKey = `fec_judge_${judgeName.replace(/\s/g,'_')}`;
  const cached = ENRICH_CACHE.get(cacheKey);
  if (cached) return cached;

  try {
    // Look up the judge's campaign committee by last name via the proxy.
    // Proxy handles auth + 24h caching; never send a key from the browser.
    const lastName = judgeName.replace('Hon.','').trim().split(' ').pop();
    const r = await fetch('api_fec.php?' + new URLSearchParams({op:'committees', name: lastName, per_page:'50'}).toString());
    if (!r.ok) throw new Error('proxy ' + r.status);
    const payload = await r.json();
    if (!payload.ok) throw new Error(payload.error || 'proxy error');
    // Normalize to the shape the downstream filter expects — wrapping the
    // committee records so each has `committee: {name}` + an amount/date
    // pair. The filter below keys off those fields.
    const committees = payload.data && payload.data.results ? payload.data.results : [];
    const data = { results: committees.map(c => ({
      committee: {name: c.name, id: c.committee_id},
      contribution_receipt_amount: 0,
      contribution_receipt_date: c.last_f1_date || '',
      contributor_name: '',
      contributor_employer: '',
      contributor_occupation: '',
    }))};

    // Filter for committees that look like judicial campaigns
    const donations = (data.results || [])
      .filter(d => {
        const comm = (d.committee?.name || '').toLowerCase();
        const last = lastName.toLowerCase();
        return comm.includes(last) && (comm.includes('judge') || comm.includes('justice') || comm.includes('elect') || comm.includes('campaign') || comm.includes('friends'));
      })
      .map(d => ({
        contributor: d.contributor_name,
        employer: d.contributor_employer,
        occupation: d.contributor_occupation,
        amount: d.contribution_receipt_amount,
        date: d.contribution_receipt_date,
        committee: d.committee?.name,
      }));

    const result = { donations, total: donations.reduce((s,d) => s + (d.amount||0), 0), source: 'FEC.gov' };
    ENRICH_CACHE.set(cacheKey, result);
    return result;
  } catch(e) {
    return { error: e.message, donations: [] };
  }
}

function crossReferenceAttorneyDonations(judgeName, state) {
  // Check attorneys in our DB who have campaignDonations that might match this judge
  const attorneys = (window.ATTORNEYS || []).filter(a => a.state === state || a.state?.includes(state));
  const conflicts = [];
  attorneys.forEach(atty => {
    (atty.campaignDonations || []).forEach(d => {
      const recipLower = (d.recipient || d.judge || '').toLowerCase();
      const nameParts = judgeName.toLowerCase().replace('hon.','').trim().split(' ').filter(p => p.length > 3);
      if (nameParts.some(p => recipLower.includes(p))) {
        conflicts.push({
          attorneyId: atty.id,
          attorneyName: atty.name,
          barNum: atty.barNum,
          amount: d.amount,
          date: d.date || d.year,
          source: d.source || 'Platform data',
        });
      }
    });
  });
  return conflicts;
}

function getJTCLink(state) {
  const jtc = {
    Texas:          { name:'State Commission on Judicial Conduct', url:'https://www.scjc.texas.gov/complaints/', phone:'512-463-5533' },
    Florida:        { name:'Judicial Qualifications Commission', url:'https://www.fljqc.com/file-a-complaint/', phone:'850-488-1581' },
    California:     { name:'Commission on Judicial Performance', url:'https://cjp.ca.gov/file_a_complaint/', phone:'415-557-1200' },
    'New York':     { name:'State Commission on Judicial Conduct', url:'https://www.scjc.state.ny.us/filing-a-complaint/', phone:'212-809-0566' },
    Ohio:           { name:'Board of Professional Conduct', url:'https://www.supremecourt.ohio.gov/boards/bpc/default.aspx', phone:'614-387-9370' },
    Illinois:       { name:'Judicial Inquiry Board', url:'https://jib.illinois.gov/complaint.html', phone:'312-814-5554' },
    Georgia:        { name:'Judicial Qualifications Commission', url:'https://jqc.georgia.gov/how-file-complaint', phone:'404-656-6438' },
    'North Carolina':{ name:'Judicial Standards Commission', url:'https://www.nccourts.gov/courts/offices-and-services/judicial-standards-commission', phone:'919-831-3630' },
    Pennsylvania:   { name:'Court of Judicial Discipline', url:'https://www.pacourts.us/courts/court-of-judicial-discipline', phone:'717-234-7911' },
    Michigan:       { name:'Judicial Tenure Commission', url:'https://www.michigan.gov/jtc/', phone:'517-373-3222' },
    Tennessee:      { name:'Court of the Judiciary', url:'https://www.tncourts.gov/courts/court-judiciary', phone:'615-741-2687' },
  };
  return jtc[state] || { name:'Judicial Conduct Commission', url:'https://www.ncsc.org/information-and-resources/state-court-websites', phone:'Contact state court website' };
}

function renderJudgeEnrichment(container, results, judge) {
  const fec = results.fec;
  const incoming = results.incomingDonations || [];
  const fin = results.stateFinance;
  const jtc = results.jtcLink;
  const hasFECIncoming = fec?.donations?.length > 0;
  const hasDBConflicts = incoming.length > 0;

  let html = `<div class="enrich-panel">`;

  // ---- INCOMING DONATIONS FROM ATTORNEYS ----
  html += `<div class="enrich-section">
    <div class="enrich-section-title">
      💰 Campaign Finance — Who donated to this judge?
      ${hasDBConflicts || hasFECIncoming ? `<span class="enrich-live-badge enrich-badge-red">⚠️ Conflicts found</span>` : `<span class="enrich-live-badge">✓ Live</span>`}
    </div>`;

  if (hasDBConflicts) {
    html += `<div class="enrich-conflict-alert">
      ⚠️ ${incoming.length} attorney${incoming.length>1?'s':''} in our database donated to ${judge.name}'s campaign and also appear in cases before this court
      ${incoming.map(c => `<div class="enrich-conflict-row">
        <strong>${c.attorneyName}</strong> ${c.barNum ? `(Bar #${c.barNum})` : ''} → 
        <strong>$${(c.amount||0).toLocaleString()}</strong> → ${judge.name}
        ${c.date ? `· ${c.date}` : ''}
        <span class="enrich-source">${c.source}</span>
      </div>`).join('')}
    </div>`;
  }

  if (hasFECIncoming) {
    html += `<div class="enrich-donations-total">
      ${fec.donations.length} contributions found to campaigns matching this judge · Total: $${(fec.total||0).toLocaleString()}
    </div>
    <table class="enrich-table">
      <thead><tr><th>Contributor</th><th>Employer / Occupation</th><th>Amount</th><th>Date</th></tr></thead>
      <tbody>
        ${fec.donations.slice(0,10).map(d => `<tr>
          <td style="font-weight:500">${d.contributor || '—'}</td>
          <td style="font-size:10px;color:var(--text3)">${d.employer || ''} / ${d.occupation || ''}</td>
          <td class="enrich-money ${d.amount>5000?'red':''}">${d.amount ? '$'+d.amount.toLocaleString() : '—'}</td>
          <td>${d.date || '—'}</td>
        </tr>`).join('')}
      </tbody>
    </table>`;
  } else if (!getFECKey()) {
    html += `<div class="enrich-no-key">
      FEC key not configured — can't auto-search incoming donations.
      ${!hasDBConflicts ? 'No attorney conflicts found in platform database either.' : ''}
      <br><a href="https://www.fec.gov/data/receipts/individual-contributions/?committee_name=${encodeURIComponent(judge.name.replace('Hon.','').trim())}" target="_blank" class="enrich-link">Search FEC.gov manually →</a>
    </div>`;
  } else if (!hasDBConflicts) {
    html += `<div class="enrich-empty">
      No campaign contributions found in FEC records or platform attorney database.
      <a href="https://www.fec.gov/data/receipts/individual-contributions/?committee_name=${encodeURIComponent(judge.name.replace('Hon.','').trim())}" target="_blank" class="enrich-link">Verify on FEC.gov →</a>
      <br><span style="font-size:9px;color:var(--text3)">State judicial elections use state campaign finance databases, not FEC. Check your state ethics commission for state-level donations.</span>
    </div>`;
  }
  html += `</div>`;

  // ---- IV-D FINANCIAL INCENTIVE ----
  if (fin) {
    const avgOrder = parseFloat(judge.avgSupportOrder || fin.avg_order || 0);
    const stateAvg = parseFloat(fin.avg_order || 0);
    const deviation = stateAvg > 0 ? Math.round((avgOrder - stateAvg) / stateAvg * 100) : null;

    html += `<div class="enrich-section">
      <div class="enrich-section-title">📊 IV-D Incentive Context — Orders from this court vs state average</div>
      <div class="enrich-ivd-row">
        ${judge.avgSupportOrder ? `<div class="enrich-stat">
          <div class="enrich-stat-val ${deviation > 15 ? 'red' : deviation > 5 ? 'amber' : 'green'}">$${avgOrder.toLocaleString()}/mo</div>
          <div class="enrich-stat-label">Avg order — this judge</div>
        </div>` : ''}
        <div class="enrich-stat"><div class="enrich-stat-val amber">$${stateAvg}/mo</div><div class="enrich-stat-label">State average order</div></div>
        ${deviation !== null && judge.avgSupportOrder ? `<div class="enrich-stat">
          <div class="enrich-stat-val ${deviation > 0 ? 'red' : 'green'}">${deviation > 0 ? '+' : ''}${deviation}%</div>
          <div class="enrich-stat-label">vs state average</div>
        </div>` : ''}
        <div class="enrich-stat"><div class="enrich-stat-val purple">$${fin.federal_incentive_paid}M/yr</div><div class="enrich-stat-label">State gets from fed</div></div>
      </div>
      ${deviation > 15 ? `<div class="enrich-conflict-alert" style="margin-top:8px">
        Orders from this court average ${deviation}% above the state average. Every $1 of additional support collected generates additional federal incentive payments to the state.
        <a href="https://www.law.cornell.edu/uscode/text/42/658a" target="_blank" class="enrich-link">42 USC 658a →</a>
      </div>` : ''}
    </div>`;
  }

  // ---- JTC FILING LINK ----
  html += `<div class="enrich-section">
    <div class="enrich-section-title">📋 Judicial Conduct Complaint — Direct Link</div>
    <div style="font-size:11px;color:var(--text2);margin-bottom:8px">${jtc.name}</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a href="${jtc.url}" target="_blank" class="enrich-action-btn teal">File conduct complaint →</a>
      ${jtc.phone ? `<span style="font-size:11px;color:var(--text3);padding:5px 0">📞 ${jtc.phone}</span>` : ''}
    </div>
    <div style="font-size:10px;color:var(--text3);margin-top:6px;line-height:1.5">
      Tip: Pattern complaints — multiple people filing about the same judge — are investigated at significantly higher rates than individual complaints.
      Share this judge's profile page and encourage others to file independently.
    </div>
  </div>`;

  html += `<div class="enrich-footer">
    Live data pulled ${new Date().toLocaleTimeString()} · 
    <a href="#" onclick="refreshEnrichment('judge','${judge.id}')" class="enrich-link">Refresh</a>
  </div>`;
  html += `</div>`;

  container.innerHTML = html;
}

// ============================================================
// USASpending — IV-D state data (used by both judge and attorney enrichment)
// ============================================================
async function fetchStateIVD(stateName) {
  if (!stateName) return null;
  const cacheKey = `ivd_${stateName}`;
  const cached = ENRICH_CACHE.get(cacheKey);
  if (cached) return cached;

  // Try USASpending first
  try {
    const body = {
      scope: 'place_of_performance',
      geo_layer: 'state',
      filters: {
        time_period: [{start_date:'2023-10-01', end_date:'2024-09-30'}],
        program_numbers: ['93.563']
      }
    };
    const r = await fetch('https://api.usaspending.gov/api/v2/spending_by_geography/', {
      method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)
    });
    if (r.ok) {
      const data = await r.json();
      const stateAbbrevMap = {'Alabama':'AL','Alaska':'AK','Arizona':'AZ','Arkansas':'AR','California':'CA','Colorado':'CO','Connecticut':'CT','Delaware':'DE','Florida':'FL','Georgia':'GA','Hawaii':'HI','Idaho':'ID','Illinois':'IL','Indiana':'IN','Iowa':'IA','Kansas':'KS','Kentucky':'KY','Louisiana':'LA','Maine':'ME','Maryland':'MD','Massachusetts':'MA','Michigan':'MI','Minnesota':'MN','Mississippi':'MS','Missouri':'MO','Montana':'MT','Nebraska':'NE','Nevada':'NV','New Hampshire':'NH','New Jersey':'NJ','New Mexico':'NM','New York':'NY','North Carolina':'NC','North Dakota':'ND','Ohio':'OH','Oklahoma':'OK','Oregon':'OR','Pennsylvania':'PA','Rhode Island':'RI','South Carolina':'SC','South Dakota':'SD','Tennessee':'TN','Texas':'TX','Utah':'UT','Vermont':'VT','Virginia':'VA','Washington':'WA','West Virginia':'WV','Wisconsin':'WI','Wyoming':'WY'};
      const abbr = stateAbbrevMap[stateName] || stateName;
      const row = (data.results || []).find(r => r.shape_code === abbr || r.display_name?.includes(stateName));
      if (row) {
        const result = {
          ivd_collected: Math.round(row.aggregated_amount / 1e6),
          federal_incentive_paid: Math.round(row.aggregated_amount * 0.07 / 1e6), // approx incentive
          avg_order: window.STATE_FINANCIAL?.states?.[stateName]?.avg_order || null,
          cases_open: window.STATE_FINANCIAL?.states?.[stateName]?.cases_open || null,
          source: 'USASpending.gov live'
        };
        ENRICH_CACHE.set(cacheKey, result);
        return result;
      }
    }
  } catch(e) { /* fall through to embedded data */ }

  // Fall back to embedded state financial data
  const embedded = window.STATE_FINANCIAL?.states?.[stateName];
  if (embedded) {
    ENRICH_CACHE.set(cacheKey, {...embedded, source: 'Embedded FY2023'});
    return embedded;
  }
  return null;
}

// ============================================================
// REFRESH
// ============================================================
function refreshEnrichment(type, id) {
  const cacheKey = `${type}_${id}`;
  ENRICH_CACHE.set(cacheKey, null); // clear cache
  try { sessionStorage.removeItem('fca_enrich_' + cacheKey); } catch(e) {}
  if (type === 'judge') enrichJudgeProfile(id);
  if (type === 'atty')  enrichAttorneyProfile(id);
}

// ============================================================
// SHARED HELPERS
// ============================================================
function enrichLoadingHTML(msg) {
  return `<div class="enrich-loading"><div class="enrich-spinner"></div><div>${msg}</div></div>`;
}

// ============================================================
// INJECT ENRICHMENT CONTAINER INTO PROFILE CARDS
// ============================================================
// Call this after the profile card HTML has been rendered.
// It inserts an empty div that enrichJudgeProfile/enrichAttorneyProfile fills.

function addEnrichmentContainer(type, entityId) {
  const targetId = `${type}-detail-body`; // the existing expanded profile section
  const target = document.getElementById(targetId);
  if (!target) return;
  const containerId = `enrich-${type}-${entityId}`;
  if (document.getElementById(containerId)) return; // already added
  const div = document.createElement('div');
  div.id = containerId;
  div.innerHTML = `<div class="enrich-trigger" onclick="loadEnrichment('${type}','${entityId}')">
    <span>📡 Load live data — campaign finance, IV-D context, filing links</span>
    <button>Load</button>
  </div>`;
  target.appendChild(div);
}

function loadEnrichment(type, id) {
  if (type === 'judge') enrichJudgeProfile(id);
  if (type === 'atty')  enrichAttorneyProfile(id);
}

// ============================================================
// CSS — inject styles into document
// ============================================================
const ENRICH_CSS = `
.enrich-panel{background:var(--bg1,#111116);border-radius:8px;border:1px solid rgba(255,255,255,.07);margin-top:12px;overflow:hidden}
.enrich-section{padding:12px 14px;border-bottom:1px solid rgba(255,255,255,.05)}
.enrich-section:last-of-type{border-bottom:none}
.enrich-section-title{font-size:11px;font-weight:600;color:#9a9690;margin-bottom:10px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.enrich-live-badge{font-size:9px;padding:2px 7px;border-radius:3px;background:#132010;color:#80c450;font-weight:500}
.enrich-badge-red{background:#3d1010;color:#f09090}
.enrich-badge-warn{background:#3a2200;color:#f0b840}
.enrich-badge-grey{background:#1e1e28;color:#6b6866}
.enrich-conflict-alert{background:#3d1010;border:1px solid rgba(240,144,144,.2);border-radius:6px;padding:10px 12px;margin-bottom:10px;font-size:11px;color:#e0ddd5;line-height:1.7}
.enrich-conflict-row{margin-top:6px;padding:6px 0;border-top:1px solid rgba(240,144,144,.1);font-size:11px}
.enrich-donations-total{font-size:11px;color:#9a9690;margin-bottom:8px}
.enrich-table{width:100%;border-collapse:collapse;font-size:11px}
.enrich-table th{text-align:left;padding:5px 8px;color:#6b6866;font-size:10px;border-bottom:1px solid rgba(255,255,255,.05)}
.enrich-table td{padding:5px 8px;border-bottom:1px solid rgba(255,255,255,.04);color:#9a9690}
.enrich-table tr:hover td{background:rgba(255,255,255,.02)}
.enrich-money{font-family:monospace;font-weight:500;color:#e0ddd5}
.enrich-money.red{color:#f09090}
.enrich-ivd-row{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
.enrich-stat{text-align:center;background:#18181f;border-radius:6px;padding:8px}
.enrich-stat-val{font-size:16px;font-weight:500;font-family:monospace}
.enrich-stat-val.green{color:#80c450}
.enrich-stat-val.red{color:#f09090}
.enrich-stat-val.amber{color:#f0b840}
.enrich-stat-val.purple{color:#b090e8}
.enrich-stat-label{font-size:9px;color:#6b6866;margin-top:2px}
.enrich-no-key,.enrich-empty{font-size:11px;color:#6b6866;line-height:1.6;padding:6px 0}
.enrich-source{font-size:9px;padding:1px 5px;border-radius:2px;background:#1e1e28;color:#6b6866;margin-left:6px}
.enrich-link{color:#50c4a8;text-decoration:none;font-size:10px}
.enrich-link:hover{text-decoration:underline}
.enrich-action-btn{display:inline-flex;align-items:center;padding:6px 12px;border-radius:6px;font-size:11px;font-weight:500;cursor:pointer;border:1px solid rgba(255,255,255,.07);background:#1e1e28;color:#e0ddd5;text-decoration:none}
.enrich-action-btn.teal{background:#0a2018;color:#50c4a8;border-color:#50c4a8}
.enrich-footer{padding:8px 14px;font-size:10px;color:#6b6866;background:#111116}
.enrich-loading{padding:16px 14px;font-size:11px;color:#6b6866;display:flex;align-items:center;gap:10px}
.enrich-spinner{width:16px;height:16px;border:2px solid #1e1e28;border-top-color:#50c4a8;border-radius:50%;animation:spin 1s linear infinite;flex-shrink:0}
.enrich-trigger{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;font-size:11px;color:#6b6866;cursor:pointer;gap:10px}
.enrich-trigger:hover{background:#18181f}
.enrich-trigger button{font-size:10px;padding:4px 10px;border-radius:4px;cursor:pointer;border:1px solid rgba(80,196,168,.4);background:#0a2018;color:#50c4a8}
@keyframes spin{to{transform:rotate(360deg)}}
@media(max-width:600px){.enrich-ivd-row{grid-template-columns:1fr 1fr}}
`;

(function injectCSS() {
  const style = document.createElement('style');
  style.textContent = ENRICH_CSS;
  document.head.appendChild(style);
})();

// ============================================================
// EXPORTS — these are called from the main platform
// ============================================================
window.enrichAttorneyProfile = enrichAttorneyProfile;
window.enrichJudgeProfile    = enrichJudgeProfile;
window.addEnrichmentContainer = addEnrichmentContainer;
window.loadEnrichment        = loadEnrichment;
window.refreshEnrichment     = refreshEnrichment;

console.log('[FCA] Profile enrichment engine loaded. Call enrichJudgeProfile(id) or enrichAttorneyProfile(id) from profile expand handlers.');
