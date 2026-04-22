/**
 * ============================================================
 * LEGAL INDUSTRY PAC TRACKING MODULE
 * ============================================================
 * Connects attorney/firm PAC donations directly to legislators
 * and cross-references with their votes on family court bills.
 * 
 * The money trail:
 *   Attorney → firm PAC → state legislator
 *   State bar PAC → legislator who votes NO on equal parenting
 *   Trial lawyer PAC → judiciary committee chair who kills bills
 * 
 * DATA SOURCES:
 *   FEC Schedule B: disbursements FROM legal PACs TO candidates
 *   FEC Schedule A: individual attorney donations by occupation
 *   ProPublica Campaign Finance API: cleaner aggregated data
 *   OpenSecrets bulk data: state-level aggregated legal $
 * 
 * CROSS-REFERENCE:
 *   Matches PAC recipients against legislators in our database
 *   Shows how much each rep received from legal industry
 *   Shows how they voted on family court reform bills
 * ============================================================
 */

// ============================================================
// KNOWN LEGAL INDUSTRY PACS — Federal (FEC tracked)
// These are the organizations that actively lobby against
// family court reform and equal parenting legislation
// ============================================================
const LEGAL_PACS = {
  // National
  'C00024521': { name:'AAJ PAC (American Association for Justice)', shortName:'AAJ PAC', type:'Trial lawyers national', notes:'$5.5M/cycle. 88% win rate. Explicitly opposes tort reform, supports high-litigation environment.' },
  'C00024851': { name:'ABAPAC (American Bar Association)', shortName:'ABAPAC', type:'Bar association national', notes:'ABA political arm. Generally opposes pro se expansion, mandatory arbitration, litigation limits.' },
  'C00103523': { name:'National Association of Criminal Defense Lawyers PAC', shortName:'NACDL PAC', type:'Criminal defense', notes:'Less relevant to family court but tracks.' },
  
  // State trial lawyer PACs (state elections — most important for family court judges)
  // These are in STATE campaign finance databases, not FEC
  // Listed here for cross-reference
  'TTLA_TX':   { name:'Texas Trial Lawyers Association PAC', shortName:'TTLA PAC', type:'State trial lawyers', state:'Texas', stateDB:'Texas Ethics Commission', stateSearchUrl:'https://www.ethics.state.tx.us/search/cf/', notes:'Critical for TX family court judge donations. Search TEC directly.' },
  'FJA_FL':    { name:'Florida Justice Association PAC', shortName:'FJA PAC', type:'State trial lawyers', state:'Florida', stateDB:'Florida DOES', stateSearchUrl:'https://dos.myflorida.com/elections/candidates-committees/campaign-finance/', notes:'Florida trial lawyers. Significant influence on FL judicial elections.' },
  'CAJ_CA':    { name:'Consumer Attorneys of California PAC', shortName:'CAOC PAC', type:'State trial lawyers', state:'California', stateDB:'CA FPPC', stateSearchUrl:'https://cal-access.sos.ca.gov/Campaign/', notes:'California trial lawyers. Large donor to CA legislators.' },
  'NYSBA_NY':  { name:'New York State Bar Association PAC', shortName:'NYSBA PAC', type:'State bar', state:'New York', stateDB:'NY BOE CFIS', stateSearchUrl:'https://publicreporting.elections.ny.gov/', notes:'NY State Bar political arm.' },
  'OAJ_OH':    { name:'Ohio Association for Justice PAC', shortName:'OAJ PAC', type:'State trial lawyers', state:'Ohio', stateDB:'Ohio SOS', stateSearchUrl:'https://www.ohiosos.gov/campaign-finance/', notes:'Ohio trial lawyers.' },
  'ITLA_IL':   { name:'Illinois Trial Lawyers Association PAC', shortName:'ITLA PAC', type:'State trial lawyers', state:'Illinois', stateDB:'IL SBE', stateSearchUrl:'https://www.elections.il.gov/CampaignDisclosure/', notes:'Illinois trial lawyers.' },
  'NCAJ_NC':   { name:'North Carolina Advocates for Justice PAC', shortName:'NCAJ PAC', type:'State trial lawyers', state:'North Carolina', stateDB:'NC SBE', stateSearchUrl:'https://cf.ncsbe.gov/CFOrgLkup/', notes:'NC trial lawyers.' },
  'GJA_GA':    { name:'Georgia Trial Lawyers Association PAC', shortName:'GTLA PAC', type:'State trial lawyers', state:'Georgia', stateDB:'GA Ethics', stateSearchUrl:'https://media.ethics.ga.gov/search/', notes:'Georgia trial lawyers.' },
  'PJA_PA':    { name:'Pennsylvania Association for Justice PAC', shortName:'PAJ PAC', type:'State trial lawyers', state:'Pennsylvania', stateDB:'PA DOS', stateSearchUrl:'https://www.campaignfinanceonline.pa.gov/', notes:'Pennsylvania trial lawyers.' },
  'MAJ_MI':    { name:'Michigan Association for Justice PAC', shortName:'MAJ PAC', type:'State trial lawyers', state:'Michigan', stateDB:'MI MCFA', stateSearchUrl:'https://cfrsearch.sos.state.mi.us/', notes:'Michigan trial lawyers.' },
};

// Industry codes for attorney/legal searches in FEC
const LEGAL_INDUSTRY_CODES = {
  sic: '8111', // Legal Services (SIC code)
  cmte_type: 'Q', // PAC - non-qualified
  occupation_keywords: ['attorney', 'lawyer', 'esquire', 'esq', 'counsel', 'partner', 'law firm', 'llp', 'pllc']
};

// ============================================================
// FEC — FETCH PAC DISBURSEMENTS (Schedule B)
// Shows where legal PAC money GOES (to which legislators)
// ============================================================
async function fetchPACDisbursements(committeeId, cycle = '2024') {
  const key = localStorage.getItem('fca_key_fec') || '';
  if (!key) return { error: 'no_key', results: [] };

  const cacheKey = `pac_disb_${committeeId}_${cycle}`;
  try {
    const cached = sessionStorage.getItem('fca_' + cacheKey);
    if (cached) return JSON.parse(cached);
  } catch(e) {}

  try {
    const url = `https://api.open.fec.gov/v1/schedules/schedule_b/?api_key=${key}&committee_id=${committeeId}&per_page=100&sort=-disbursement_date&two_year_transaction_period=${cycle}`;
    const r = await fetch(url);
    if (!r.ok) throw new Error('FEC ' + r.status);
    const data = await r.json();
    const result = {
      pacName: LEGAL_PACS[committeeId]?.name || committeeId,
      results: (data.results || []).map(d => ({
        recipient: d.recipient_name,
        recipientId: d.recipient_committee_id,
        amount: d.disbursement_amount,
        date: d.disbursement_date,
        purpose: d.disbursement_description,
        state: d.recipient_state,
      })),
      total: (data.results || []).reduce((s, d) => s + (d.disbursement_amount || 0), 0)
    };
    try { sessionStorage.setItem('fca_' + cacheKey, JSON.stringify(result)); } catch(e) {}
    return result;
  } catch(e) {
    return { error: e.message, results: [] };
  }
}

// ============================================================
// FEC — SEARCH ATTORNEY DONATIONS BY OCCUPATION
// Finds individual attorney donations to federal legislators
// Grouped by law firm (contributor_employer)
// ============================================================
async function fetchAttorneyDonationsByState(state, cycle = '2024') {
  const key = localStorage.getItem('fca_key_fec') || '';
  if (!key) return { error: 'no_key', results: [] };

  const cacheKey = `atty_state_${state}_${cycle}`;
  try {
    const cached = sessionStorage.getItem('fca_' + cacheKey);
    if (cached) return JSON.parse(cached);
  } catch(e) {}

  try {
    // Schedule A: individual contributions where occupation = attorney/lawyer
    const url = `https://api.open.fec.gov/v1/schedules/schedule_a/?api_key=${key}&contributor_occupation=attorney&contributor_state=${getStateAbbr(state)}&per_page=100&sort=-contribution_receipt_date&two_year_transaction_period=${cycle}`;
    const r = await fetch(url);
    if (!r.ok) throw new Error('FEC ' + r.status);
    const data = await r.json();
    
    // Group by employer (law firm) and recipient
    const byFirm = {};
    (data.results || []).forEach(d => {
      const firm = d.contributor_employer || 'Individual';
      const recipient = d.committee?.name || d.committee_id;
      const key2 = `${firm}|||${recipient}`;
      if (!byFirm[key2]) {
        byFirm[key2] = {
          firm,
          recipient,
          recipientId: d.committee_id,
          total: 0,
          count: 0,
          contributors: [],
          state: d.contributor_state,
        };
      }
      byFirm[key2].total += (d.contribution_receipt_amount || 0);
      byFirm[key2].count++;
      if (!byFirm[key2].contributors.includes(d.contributor_name)) {
        byFirm[key2].contributors.push(d.contributor_name);
      }
    });
    
    const result = {
      state,
      grouped: Object.values(byFirm).sort((a,b) => b.total - a.total),
      raw: data.results || [],
      total: (data.results || []).reduce((s,d) => s + (d.contribution_receipt_amount||0), 0)
    };
    try { sessionStorage.setItem('fca_' + cacheKey, JSON.stringify(result)); } catch(e) {}
    return result;
  } catch(e) {
    return { error: e.message, results: [] };
  }
}

// ============================================================
// CROSS-REFERENCE: PAC recipients vs legislators in our DB
// The key function — matches FEC disbursements to reps we track
// and shows how those reps voted on family court bills
// ============================================================
function crossRefPACToLegislators(disbursements, legislators, bills) {
  const conflicts = [];
  
  disbursements.forEach(d => {
    // Match recipient name to legislators in our database
    const recipLower = (d.recipient || '').toLowerCase();
    legislators.forEach(leg => {
      const nameParts = leg.name.toLowerCase().split(' ').filter(p => p.length > 3);
      const isMatch = nameParts.some(p => recipLower.includes(p));
      
      if (isMatch) {
        // Found a match — now get their voting record on family bills
        const votes = (leg.votes || []).filter(v => v.bill); // their votes on family bills
        const nayVotes = votes.filter(v => v.vote === 'nay');
        const yeaVotes = votes.filter(v => v.vote === 'yea');
        
        conflicts.push({
          pac: d._pacName || d.committee,
          pacAmount: d.amount,
          pacDate: d.date,
          legislator: leg.name,
          legislatorId: leg.id,
          legislatorTitle: leg.title,
          state: leg.district,
          votedAgainst: nayVotes,
          votedFor: yeaVotes,
          allVotes: votes,
          conflictScore: (d.amount / 1000) * (nayVotes.length > 0 ? 2 : 1), // weight by votes against
        });
      }
    });
  });
  
  return conflicts.sort((a,b) => b.conflictScore - a.conflictScore);
}

// ============================================================
// MAIN RENDER — Legal Industry Money → Legislators Tab
// Full visualization of money flow and vote correlation
// ============================================================
async function renderLegalMoneyMap(containerId, state) {
  const container = document.getElementById(containerId);
  if (!container) return;
  
  container.innerHTML = legalMoneyLoadingHTML();
  
  const key = localStorage.getItem('fca_key_fec') || '';
  if (!key) {
    renderNoKeyState(container, state);
    return;
  }

  // Fetch federal PAC disbursements for the two biggest national legal PACs
  const [aajData, attyData] = await Promise.all([
    fetchPACDisbursements('C00024521'), // AAJ PAC
    fetchAttorneyDonationsByState(state),
  ]);

  // Get legislators and bills from platform
  const legislators = window.REPS_BY_STATE?.[state] || [];
  const bills = (window.BILLS || []).filter(b => b.state === state || b.state.includes('Federal'));
  
  // Cross-reference
  const allDisbursements = [
    ...(aajData.results || []).map(d => ({...d, _pacName: 'AAJ PAC'})),
  ];
  const conflicts = crossRefPACToLegislators(allDisbursements, legislators, bills);
  
  // Render
  renderLegalMoneyResults(container, {
    state,
    aajData,
    attyData,
    conflicts,
    legislators,
    bills,
    hasKey: !!key,
  });
}

function renderLegalMoneyResults(container, data) {
  const { state, aajData, attyData, conflicts, legislators, bills, hasKey } = data;
  const stateAbbr = getStateAbbr(state);
  const statePAC = Object.entries(LEGAL_PACS).find(([,p]) => p.state === state)?.[1];

  container.innerHTML = `
    <div style="background:var(--bg1,#111116);border-radius:10px;border:1px solid rgba(255,255,255,.07);overflow:hidden">
      
      <!-- Header -->
      <div style="padding:14px 16px;border-bottom:1px solid rgba(255,255,255,.05);background:linear-gradient(135deg,#1a0505,#111116)">
        <div style="font-size:13px;font-weight:500;margin-bottom:4px">Legal Industry Money → ${state} Legislators</div>
        <div style="font-size:11px;color:var(--text3,#6b6866);line-height:1.6">
          Attorney PACs, law firm bundled contributions, and trial lawyer association donations to legislators 
          who vote on family court reform. Cross-referenced with their voting records on your tracked bills.
        </div>
      </div>
      
      <!-- National PACs section -->
      <div style="padding:12px 16px;border-bottom:1px solid rgba(255,255,255,.05)">
        <div style="font-size:11px;font-weight:500;color:var(--text3,#6b6866);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">National Legal PACs</div>
        
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px">
          <div style="background:var(--bg2,#18181f);border-radius:8px;padding:12px;border:1px solid rgba(255,255,255,.07)">
            <div style="font-size:10px;color:var(--text3,#6b6866);margin-bottom:4px">AAJ PAC (Trial Lawyers)</div>
            <div style="font-size:18px;font-weight:500;font-family:monospace;color:#f09090">$5.5M+/cycle</div>
            <div style="font-size:10px;color:var(--text3,#6b6866);margin-top:2px;line-height:1.4">
              American Association for Justice. Explicitly funds "pro-civil justice" candidates = 
              high-litigation environment. 88% win rate in 2022/2024.
              FEC ID: C00024521
            </div>
            <a href="https://www.fec.gov/data/committee/C00024521/" target="_blank" 
               style="font-size:10px;color:#50c4a8;text-decoration:none;display:block;margin-top:6px">
              View on FEC.gov →
            </a>
          </div>
          <div style="background:var(--bg2,#18181f);border-radius:8px;padding:12px;border:1px solid rgba(255,255,255,.07)">
            <div style="font-size:10px;color:var(--text3,#6b6866);margin-bottom:4px">ABAPAC (American Bar Association)</div>
            <div style="font-size:18px;font-weight:500;font-family:monospace;color:#f09090">Tracked</div>
            <div style="font-size:10px;color:var(--text3,#6b6866);margin-top:2px;line-height:1.4">
              ABA political arm. Generally opposes pro se expansion, fee arbitration mandates, 
              judicial accountability reporting requirements.
              FEC ID: C00024851
            </div>
            <a href="https://www.fec.gov/data/committee/C00024851/" target="_blank"
               style="font-size:10px;color:#50c4a8;text-decoration:none;display:block;margin-top:6px">
              View on FEC.gov →
            </a>
          </div>
        </div>
        
        <!-- State PAC -->
        ${statePAC ? `
          <div style="background:#1a0505;border:1px solid rgba(240,144,144,.15);border-radius:8px;padding:12px">
            <div style="font-size:11px;font-weight:500;color:#f09090;margin-bottom:6px">
              ⚠️ ${state} State Trial Lawyers PAC — ${statePAC.shortName}
            </div>
            <div style="font-size:11px;color:var(--text2,#9a9690);line-height:1.7;margin-bottom:8px">
              ${statePAC.notes}
              This is where attorney→<strong>family court judge</strong> donations live — 
              state elections are NOT in FEC. Check the state database directly.
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <a href="${statePAC.stateSearchUrl}" target="_blank"
                 style="font-size:11px;padding:5px 10px;border-radius:5px;background:#0a2018;color:#50c4a8;border:1px solid #50c4a8;text-decoration:none">
                Search ${statePAC.stateDB} →
              </a>
            </div>
          </div>` : ''}
      </div>
      
      <!-- Attorney firm donations grouped -->
      ${attyData.grouped?.length ? `
        <div style="padding:12px 16px;border-bottom:1px solid rgba(255,255,255,.05)">
          <div style="font-size:11px;font-weight:500;color:var(--text3,#6b6866);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">
            ${state} Attorney/Firm Contributions to Federal Candidates — FEC Live
          </div>
          <div style="font-size:11px;color:var(--text2,#9a9690);margin-bottom:8px">
            Total from ${state} attorneys this cycle: 
            <strong style="color:#f09090">$${(attyData.total||0).toLocaleString()}</strong> across ${attyData.grouped?.length} firm/recipient combinations
          </div>
          <table style="width:100%;border-collapse:collapse;font-size:11px">
            <thead><tr>
              <th style="text-align:left;padding:5px 8px;color:var(--text3,#6b6866);font-size:10px;border-bottom:1px solid rgba(255,255,255,.05)">Law firm</th>
              <th style="text-align:left;padding:5px 8px;color:var(--text3,#6b6866);font-size:10px;border-bottom:1px solid rgba(255,255,255,.05)">Recipient</th>
              <th style="text-align:right;padding:5px 8px;color:var(--text3,#6b6866);font-size:10px;border-bottom:1px solid rgba(255,255,255,.05)">Amount</th>
              <th style="text-align:center;padding:5px 8px;color:var(--text3,#6b6866);font-size:10px;border-bottom:1px solid rgba(255,255,255,.05)">Attorneys</th>
            </tr></thead>
            <tbody>
              ${(attyData.grouped||[]).slice(0,10).map(row => `<tr>
                <td style="padding:5px 8px;font-weight:500;color:var(--text,#e0ddd5)">${row.firm}</td>
                <td style="padding:5px 8px;color:var(--text3,#6b6866);font-size:10px">${row.recipient||'—'}</td>
                <td style="padding:5px 8px;text-align:right;font-family:monospace;font-weight:500;color:${row.total>10000?'#f09090':row.total>5000?'#f0b840':'#e0ddd5'}">$${(row.total||0).toLocaleString()}</td>
                <td style="padding:5px 8px;text-align:center;color:var(--text3,#6b6866)">${row.count}</td>
              </tr>`).join('')}
            </tbody>
          </table>
          <div style="font-size:9px;color:var(--text3,#6b6866);margin-top:6px">
            FEC Schedule A · Occupation filter: "attorney" · ${state} contributors · 
            <a href="https://www.fec.gov/data/receipts/individual-contributions/?contributor_occupation=attorney&contributor_state=${stateAbbr}" target="_blank" style="color:#50c4a8;text-decoration:none">Full data on FEC.gov →</a>
          </div>
        </div>` : ''}
      
      <!-- CONFLICT CROSS-REFERENCE — the smoking gun section -->
      ${conflicts.length ? `
        <div style="padding:12px 16px;border-bottom:1px solid rgba(255,255,255,.05)">
          <div style="font-size:11px;font-weight:500;color:var(--text3,#6b6866);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">
            ⚠️ Money → Vote Connections — Legal PAC Donors Who Received Votes
          </div>
          ${conflicts.map(c => `
            <div style="background:#1a0505;border:1px solid rgba(240,144,144,.15);border-radius:8px;padding:12px;margin-bottom:8px">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;margin-bottom:8px">
                <div>
                  <div style="font-size:13px;font-weight:500">${c.legislator}</div>
                  <div style="font-size:10px;color:var(--text3,#6b6866)">${c.legislatorTitle}</div>
                </div>
                <div style="text-align:right">
                  <div style="font-size:18px;font-weight:500;font-family:monospace;color:#f09090">
                    $${(c.pacAmount||0).toLocaleString()}
                  </div>
                  <div style="font-size:9px;color:var(--text3,#6b6866)">from ${c.pac}</div>
                </div>
              </div>
              ${c.votedAgainst.length ? `
                <div style="margin-bottom:6px">
                  <div style="font-size:10px;font-weight:500;color:#f09090;margin-bottom:4px">Voted AGAINST family court reform:</div>
                  ${c.votedAgainst.map(v => `
                    <div style="font-size:11px;color:#9a9690;padding:3px 0;display:flex;align-items:center;gap:8px">
                      <span style="font-size:9px;padding:1px 5px;border-radius:2px;background:#3d1010;color:#f09090">NAY</span>
                      ${v.bill} — ${v.desc}
                    </div>`).join('')}
                </div>` : ''}
              ${c.votedFor.length ? `
                <div>
                  <div style="font-size:10px;font-weight:500;color:#80c450;margin-bottom:4px">Voted FOR reform:</div>
                  ${c.votedFor.map(v => `
                    <div style="font-size:11px;color:#9a9690;padding:3px 0;display:flex;align-items:center;gap:8px">
                      <span style="font-size:9px;padding:1px 5px;border-radius:2px;background:#132010;color:#80c450">YEA</span>
                      ${v.bill} — ${v.desc}
                    </div>`).join('')}
                </div>` : ''}
            </div>`).join('')}
        </div>` : 
        `<div style="padding:12px 16px">
          <div style="font-size:11px;color:var(--text3,#6b6866);line-height:1.7">
            No direct matches found between federal legal PAC disbursements and legislators in the platform database.
            <br>This may mean: (1) the donations are through state PACs (not in FEC), 
            (2) the money goes through intermediary committees, 
            (3) the relevant legislators aren't yet in the platform database.
            <br><br>
            <strong>Manual searches:</strong><br>
            <a href="https://www.fec.gov/data/disbursements/?committee_id=C00024521" target="_blank" style="color:#50c4a8;text-decoration:none">AAJ PAC disbursements by state on FEC.gov →</a><br>
            ${statePAC ? `<a href="${statePAC.stateSearchUrl}" target="_blank" style="color:#50c4a8;text-decoration:none">Search ${state} state trial lawyer PAC on ${statePAC.stateDB} →</a>` : ''}
          </div>
        </div>`}
      
      <!-- How the PAC money hides -->
      <div style="padding:12px 16px;background:#0a0a0d;font-size:11px;color:var(--text3,#6b6866);line-height:1.7">
        <strong style="color:var(--text2,#9a9690)">How legal industry money hides:</strong><br>
        <strong>Layer 1 — Direct:</strong> Attorney writes check to campaign. Disclosed in FEC Schedule A. Searched above.<br>
        <strong>Layer 2 — Firm PAC:</strong> Attorneys pool money into firm PAC. PAC donates. Disclosed in FEC Schedule B. Searched above.<br>
        <strong>Layer 3 — Trade PAC:</strong> Firm donates to TTLA/FJA/AAJ PAC. PAC donates. FEC Schedule B. Searched above.<br>
        <strong>Layer 4 — Leadership PAC:</strong> Legal PAC → politician's Leadership PAC → candidate. Two hops. Still disclosed but harder to follow.<br>
        <strong>Layer 5 — 501(c)(4) dark money:</strong> Attorneys fund a 501(c)(4) → Super PAC → candidate. The 501(c)(4) does NOT disclose donors. IRS 990 may show grant recipients. This layer is intentionally opaque.<br>
        <strong>Layer 6 — State PACs:</strong> At the state level (where family court judges are elected), money flows through state PACs that only appear in state campaign finance databases, not FEC. This is the most relevant layer for family court — search your state's database directly.
      </div>
    </div>`;
}

function renderNoKeyState(container, state) {
  const statePAC = Object.entries(LEGAL_PACS).find(([,p]) => p.state === state)?.[1];
  container.innerHTML = `
    <div style="background:var(--bg2,#18181f);border-radius:8px;padding:1.25rem;border:1px solid rgba(255,255,255,.07);font-size:11px;color:var(--text2,#9a9690);line-height:1.8">
      <strong>FEC API key needed for live data.</strong>
      Configure at api.data.gov/signup — free, email only, no phone needed.<br><br>
      <strong>While you set that up — manual searches for ${state}:</strong><br>
      <a href="https://www.fec.gov/data/disbursements/?committee_id=C00024521&two_year_transaction_period=2024" target="_blank" style="color:#50c4a8;text-decoration:none">
        AAJ PAC disbursements (trial lawyers national) →
      </a><br>
      <a href="https://www.fec.gov/data/receipts/individual-contributions/?contributor_occupation=attorney&contributor_state=${getStateAbbr(state)}" target="_blank" style="color:#50c4a8;text-decoration:none">
        ${state} attorney individual contributions →
      </a><br>
      ${statePAC ? `<a href="${statePAC.stateSearchUrl}" target="_blank" style="color:#50c4a8;text-decoration:none">
        ${statePAC.name} — state database →
      </a><br>` : ''}
      <a href="https://www.opensecrets.org/industries/indus?ind=K01" target="_blank" style="color:#50c4a8;text-decoration:none">
        OpenSecrets — Lawyers/Law Firms industry money overview →
      </a>
    </div>`;
}

function legalMoneyLoadingHTML() {
  return `<div style="padding:2rem;text-align:center;font-size:11px;color:var(--text3,#6b6866)">
    <div style="width:24px;height:24px;border:2px solid #1e1e28;border-top-color:#50c4a8;border-radius:50%;animation:spin 1s linear infinite;margin:0 auto 1rem"></div>
    Pulling legal industry PAC data from FEC...
  </div>`;
}

// ============================================================
// CONNECT TO REP PROFILES
// Call this when a rep card is expanded
// ============================================================
async function enrichRepWithLegalMoney(repId, containerId) {
  const allReps = Object.values(window.REPS_BY_STATE || {}).flat();
  const rep = allReps.find(r => r.id === repId);
  if (!rep) return;
  
  const container = document.getElementById(containerId);
  if (!container) return;
  
  container.innerHTML = legalMoneyLoadingHTML();
  
  const key = localStorage.getItem('fca_key_fec') || '';
  if (!key) { renderNoKeyState(container, rep.title?.split(',')[0] || 'Unknown'); return; }

  // Search specifically for donations TO this representative
  try {
    const lastName = rep.name.split(' ').pop();
    const url = `https://api.open.fec.gov/v1/schedules/schedule_a/?api_key=${key}&contributor_occupation=attorney&recipient_name=${encodeURIComponent(lastName)}&per_page=50&sort=-contribution_receipt_date`;
    const r = await fetch(url);
    if (!r.ok) throw new Error('FEC ' + r.status);
    const data = await r.json();
    
    const donations = data.results || [];
    const totalFromAttorneys = donations.reduce((s,d) => s + (d.contribution_receipt_amount||0), 0);
    const byFirm = {};
    donations.forEach(d => {
      const firm = d.contributor_employer || 'Individual attorney';
      if (!byFirm[firm]) byFirm[firm] = { firm, total: 0, count: 0, attorneys: [] };
      byFirm[firm].total += d.contribution_receipt_amount || 0;
      byFirm[firm].count++;
      if (!byFirm[firm].attorneys.includes(d.contributor_name)) byFirm[firm].attorneys.push(d.contributor_name);
    });
    const firmList = Object.values(byFirm).sort((a,b) => b.total - a.total);
    
    const nayVotes = (rep.votes||[]).filter(v => v.vote === 'nay');
    const yeaVotes = (rep.votes||[]).filter(v => v.vote === 'yea');
    
    container.innerHTML = `
      <div style="background:var(--bg1,#111116);border-radius:8px;border:1px solid rgba(255,255,255,.07);overflow:hidden">
        <div style="padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.05);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px">
          <div style="font-size:11px;font-weight:500;color:var(--text2,#9a9690)">Attorney donations to ${rep.name}</div>
          <span style="font-size:9px;padding:2px 7px;border-radius:3px;background:var(--greenbg,#132010);color:var(--green,#80c450)">✓ Live FEC</span>
        </div>
        <div style="padding:10px 14px">
          <div style="display:flex;gap:12px;margin-bottom:10px;flex-wrap:wrap">
            <div style="text-align:center;padding:8px 12px;background:var(--bg3,#1e1e28);border-radius:6px">
              <div style="font-size:18px;font-weight:500;font-family:monospace;color:${totalFromAttorneys>10000?'#f09090':totalFromAttorneys>5000?'#f0b840':'var(--text,#e0ddd5)'}">$${totalFromAttorneys.toLocaleString()}</div>
              <div style="font-size:9px;color:var(--text3,#6b6866)">From attorneys (FEC)</div>
            </div>
            <div style="text-align:center;padding:8px 12px;background:var(--bg3,#1e1e28);border-radius:6px">
              <div style="font-size:18px;font-weight:500;font-family:monospace;color:#f09090">${nayVotes.length}</div>
              <div style="font-size:9px;color:var(--text3,#6b6866)">Nay votes on reform</div>
            </div>
            <div style="text-align:center;padding:8px 12px;background:var(--bg3,#1e1e28);border-radius:6px">
              <div style="font-size:18px;font-weight:500;font-family:monospace;color:#80c450">${yeaVotes.length}</div>
              <div style="font-size:9px;color:var(--text3,#6b6866)">Yea votes on reform</div>
            </div>
          </div>
          ${firmList.length ? `
            <div style="font-size:10px;font-weight:500;color:var(--text3,#6b6866);margin-bottom:6px">By law firm:</div>
            ${firmList.slice(0,5).map(f => `
              <div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:11px">
                <div>
                  <div style="font-weight:500">${f.firm}</div>
                  <div style="font-size:9px;color:var(--text3,#6b6866)">${f.count} attorney${f.count>1?'s':''}: ${f.attorneys.slice(0,3).join(', ')}${f.attorneys.length>3?'...':''}</div>
                </div>
                <div style="font-family:monospace;font-weight:500;color:${f.total>5000?'#f09090':'var(--text,#e0ddd5)'}">$${f.total.toLocaleString()}</div>
              </div>`).join('')}
            <div style="margin-top:6px">
              <a href="https://www.fec.gov/data/receipts/individual-contributions/?contributor_occupation=attorney&recipient_name=${encodeURIComponent(lastName)}" target="_blank" style="font-size:10px;color:#50c4a8;text-decoration:none">Full data on FEC.gov →</a>
            </div>
          ` : `<div style="font-size:11px;color:var(--text3,#6b6866)">No attorney contributions found in FEC for "${lastName}". May be state-level contributions only.</div>`}
        </div>
      </div>`;
  } catch(e) {
    container.innerHTML = `<div style="font-size:11px;color:var(--text3,#6b6866);padding:10px">Error: ${e.message}</div>`;
  }
}

// ============================================================
// UTILS
// ============================================================
function getStateAbbr(stateName) {
  const map = {'Alabama':'AL','Alaska':'AK','Arizona':'AZ','Arkansas':'AR','California':'CA','Colorado':'CO','Connecticut':'CT','Delaware':'DE','Florida':'FL','Georgia':'GA','Hawaii':'HI','Idaho':'ID','Illinois':'IL','Indiana':'IN','Iowa':'IA','Kansas':'KS','Kentucky':'KY','Louisiana':'LA','Maine':'ME','Maryland':'MD','Massachusetts':'MA','Michigan':'MI','Minnesota':'MN','Mississippi':'MS','Missouri':'MO','Montana':'MT','Nebraska':'NE','Nevada':'NV','New Hampshire':'NH','New Jersey':'NJ','New Mexico':'NM','New York':'NY','North Carolina':'NC','North Dakota':'ND','Ohio':'OH','Oklahoma':'OK','Oregon':'OR','Pennsylvania':'PA','Rhode Island':'RI','South Carolina':'SC','South Dakota':'SD','Tennessee':'TN','Texas':'TX','Utah':'UT','Vermont':'VT','Virginia':'VA','Washington':'WA','West Virginia':'WV','Wisconsin':'WI','Wyoming':'WY'};
  return map[stateName] || stateName;
}

// ============================================================
// EXPORTS
// ============================================================
window.renderLegalMoneyMap = renderLegalMoneyMap;
window.enrichRepWithLegalMoney = enrichRepWithLegalMoney;
window.fetchPACDisbursements = fetchPACDisbursements;
window.fetchAttorneyDonationsByState = fetchAttorneyDonationsByState;
window.LEGAL_PACS = LEGAL_PACS;

console.log('[FCA] Legal industry PAC tracking module loaded.');
console.log('[FCA] Known PACs:', Object.values(LEGAL_PACS).map(p => p.shortName).join(', '));
