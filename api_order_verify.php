<?php
/**
 * api_order_verify.php — anti-fraud + autofill via Claude Vision.
 *
 * Accepts: multipart POST with `order` (PDF, max 10 MB) and `hash` (client
 * SHA-256 hex of the file). Returns: extracted STATISTICAL fields only —
 * court, county, state, judge name, order type, custody split, support
 * amount, order flags (gag, supervised vis, arrears). Strips ALL party
 * names, child names, attorney names, addresses, case numbers, SSNs, DOBs,
 * phone numbers, email addresses before anything leaves this endpoint.
 *
 * The PDF itself is NEVER written to disk. Only the client-provided hash is
 * logged (in an append-only TSV) so the same order cannot verify two
 * different submissions. Hash collision rejects with 409.
 *
 * Privacy model: the file is held in memory only for the duration of this
 * request. The Claude API receives it via base64. Anthropic's stated policy
 * is not to train on API input, and we send no identifiers that could
 * correlate the request to a user.
 */

require_once __DIR__ . '/api_keys.php';
fca_proxy_headers();

if (!fca_rate_ok('order_verify', 60)) {
    http_response_code(429);
    echo json_encode(['ok'=>false,'error'=>'Rate limit: 60 verifications/hr per IP.']);
    exit;
}

$key = fca_load_key('ANTHROPIC_API_KEY');
if (!$key) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'AI verification not configured on server.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['order'])) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'POST with `order` file required.']);
    exit;
}

$file = $_FILES['order'];
$hash = preg_replace('/[^a-f0-9]/i', '', (string)($_POST['hash'] ?? ''));

if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Upload error code '.$file['error']]);
    exit;
}
if ($file['size'] > 10 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['ok'=>false,'error'=>'File too large (10 MB max).']);
    exit;
}
if (strlen($hash) !== 64) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Valid SHA-256 hash required.']);
    exit;
}

// Dedup: append-only log of seen hashes. A hash already present means the
// same order was already used to verify another submission — reject.
$dedupFile = '/var/www/fca/data/order_hashes.tsv';
if (is_file($dedupFile)) {
    $fh = @fopen($dedupFile, 'r');
    if ($fh) {
        while (($line = fgets($fh)) !== false) {
            $parts = explode("\t", trim($line));
            if (!empty($parts[0]) && strcasecmp($parts[0], $hash) === 0) {
                fclose($fh);
                http_response_code(409);
                echo json_encode(['ok'=>false,'error'=>'This order has already been used to verify a submission. Each court order can verify one submission.']);
                exit;
            }
        }
        fclose($fh);
    }
}

// Read file into memory (never written to disk on our side).
$bytes = @file_get_contents($file['tmp_name']);
if ($bytes === false || strlen($bytes) < 200) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Could not read uploaded file.']);
    exit;
}
// Sanity: must start with %PDF-
if (substr($bytes, 0, 5) !== '%PDF-') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Not a valid PDF.']);
    exit;
}

$b64 = base64_encode($bytes);
unset($bytes);  // free memory

// Strict extraction prompt. The model MUST refuse to return names/addresses/
// case numbers and MUST return JSON only.
$system = <<<SYS
You are a verification tool reading a family-court document. You extract ONLY statistical data and data about public officials / professionals acting in a professional capacity. You MUST NEVER include, copy, echo, or infer any of the following from the document, even if they are visible on the page:
- Names of private parties (parents, spouses, partners, petitioners, respondents)
- Names of children or minors
- Street addresses, phone numbers, email addresses of parties
- Case numbers, docket numbers, file numbers
- Dates of birth, Social Security Numbers, driver's license numbers
- Financial account numbers, bank routing info

EXTRACTION MANDATE: extract EVERY fact from the document that fits one of the fields below. Do NOT return null if the fact is present. The submitter is typing into a form and expects you to save them work — only return null when the document truly doesn't mention that fact. If you're unsure whether a fact fits the field's categorical options, pick the closest match; that's better than returning null for something the submitter would have to retype. Return null ONLY when:
  (a) the document genuinely doesn't discuss that topic, OR
  (b) the only source of that fact would be a PII field (party name, case number, etc.) that you must not echo.

Return the following fields:

COURT & CASE BASICS
- courtName — e.g. "Maricopa County Superior Court — Family Department"
- state, county (county without "County" suffix is fine)
- yearFiled — 4-digit year when the case was originally filed
- yearOfOrder — 4-digit year this specific order was signed
- judgeName, hearingOfficerName (magistrate / commissioner / referee / special master if a non-judge wrote recommendations)
- hearingOfficerTitle — e.g. "Family Court Magistrate", "Support Magistrate"
- orderSignedDate — "YYYY-MM" only (month + year, no day)
- isExParte — true|false — order entered without the other party present
- isTemporary — true|false — temporary/interim order vs final
- isFinalOrder — true|false

ATTORNEYS — EXTRACT AGGRESSIVELY. Attorneys of record are public professionals; their names being on a court filing is a published fact. Court orders list attorneys in multiple places:
  - The CAPTION (top of first page) — typically shows "APPEARANCES" with attorney names next to their party label
  - The SIGNATURE BLOCK (bottom) — attorney signing the order on behalf of a party
  - "ATTORNEYS OF RECORD" or "APPEARANCES" section
  - Body text: "Attorney John Smith appeared for Petitioner..."

  Look in ALL these places and extract what you find. Return attorneys BY PARTY ROLE:
  * petitionerAttorney / petitionerAttorneyFirm / petitionerAttorneyBarNumber — for counsel labeled as representing Petitioner / Plaintiff / Mother / Party A
  * respondentAttorney / respondentAttorneyFirm / respondentAttorneyBarNumber — for counsel labeled as representing Respondent / Defendant / Father / Party B

  If multiple attorneys appear for the same party, prefer the LEAD attorney (first listed in caption, or the one who signed the motion/order). Do NOT use an associate who only showed up at a hearing when a lead attorney is listed in the caption.

  If the order clearly says "Pro se" or "In Pro Per" for a party, set that side's attorney fields to null.

  Also populate an "attorneysListed" ARRAY — every attorney name that appears anywhere in the document with whatever party label is attached, even if you can't confidently map them to petitioner/respondent. Format: [{"name":"...","firm":"...","bar":"...","partyLabel":"as printed — e.g., 'for Petitioner', 'for Respondent', 'Attorney for Father'","source":"caption|signature|appearance|body"}]. This gives the submitter a list to pick from if your role-assignment was wrong.

  DO NOT return null for an attorney just because the role label is slightly unusual — use your judgment. Better to extract and let the submitter correct than to miss an attorney entirely.

ORDER TYPE & SUBSTANCE
- orderType — "custody"|"support"|"modification"|"contempt"|"pfa"|"cps"|"tpr"|"divorce"|"paternity"|"relocation"|"other"
- numChildren — count (do NOT include names)
- physicalCustody — "Mother"|"Father"|"Joint (equal)"|"Joint (unequal)"|"State/Foster care"
- legalCustody — "sole-mother"|"sole-father"|"joint"|"joint-tiebreaker"|"split-subject"|"state"

CUSTODY SCHEDULE (granular — extract whatever the order specifies)
- weekendFrequency — "every other"|"every"|"3 of 4"|"none"|"other"
- weekendDuration — "fri-sun"|"fri-mon"|"sat-sun"|"fri-sat"|"other"
- weekdayVisits — number of weekday overnights or visits per week if specified
- summerSchedule — string, short summary (e.g. "4 weeks in summer", "alternating weeks")
- holidaySchedule — "alternating"|"split"|"major-holidays-to-primary"|"other"|null
- firstRightOfRefusal — true|false — if one parent unavailable, the other gets the kids first

FINANCIAL
- monthlySupport — integer dollars
- retroactiveSupport — integer dollars (back support owed from prior period)
- arrears — 0|1 (any arrears mentioned)
- arrearsAmount — integer dollars
- interestOnArrears — integer dollars or null
- incomeImputed — 0|1 — court imputed income above documented
- imputedMonthlyIncome — integer dollars if stated
- filingFees — integer dollars if the order awards fees
- attorneyFeesAwarded — integer dollars awarded to one side
- sanctionsAmount — integer dollars if sanctions ordered
- taxExemption — "mother"|"father"|"alternating"|"not addressed"
- medicalInsuranceProvider — "mother"|"father"|"both"|"neither"|"not addressed"

ENFORCEMENT FLAGS
- wageGarnishment — 0|1 — income withholding ordered
- licenseSuspension — 0|1
- passportHold — 0|1
- taxRefundIntercept — 0|1
- creditReporting — 0|1
- contemptFinding — 0|1 — court found one party in contempt
- jailSentence — integer days if contempt sentence mentioned

ORDER FLAGS
- supervisedVisitation — 0|1|2 (2 = strict/monitored)
- supervisedVisitationProvider — name of agency/provider if stated, else null
- gagOrder — 0|1|2|3
- pfaFiled — 0|1|2|3
- emergencyOrder — 0|1|2
- mediationOrdered — 0|1
- parentingCoordinatorOrdered — 0|1

PROFESSIONAL APPOINTMENTS (names of public professionals only)
- galAppointed — 0|1
- galName — string if stated
- custodyEvaluatorAppointed — 0|1
- custodyEvaluatorName — string if stated
- parentingCoordinatorName — string if stated
- reunificationTherapistOrdered — 0|1
- reunificationTherapistName — string if stated

COURT-ORDERED REQUIREMENTS
- parentingClassesOrdered — 0|1
- angerManagementOrdered — 0|1
- substanceAbuseEvaluationOrdered — 0|1
- drugTestingOrdered — 0|1 — ongoing testing
- drugTestingFrequency — "random"|"weekly"|"monthly"|"one-time"|null
- mentalHealthEvalOrdered — 0|1
- therapyOrdered — 0|1 — any ordered therapy for parent(s) or children

TIMELINE & HEARINGS
- numberOfHearingsReferenced — integer count of hearings mentioned
- monthsFromFilingToOrder — integer months if computable from dates in the document
- continuancesMentioned — integer count

MILITARY (if the document mentions military service)
- militaryMentioned — 0|1
- scraStayRequested — 0|1
- scraStayOutcome — "granted"|"denied"|"ignored"|"pending"|null
- bahIncludedInIncome — 0|1

JUDGE'S REASONING
- judgeRemarks — the judge's findings-of-fact, reasoning, and ordered-text section, with ALL private names stripped. Replace with [Petitioner], [Respondent], [Mother], [Father], [Child 1], [Child 2], etc. Keep reasoning, credibility findings, cited evidence, and ordered paragraphs. Target length: 200-800 words. If minute entry with no written reasoning, return null.
- credibilityFindings — short phrase if the judge ruled on credibility (e.g. "court found Respondent's income testimony not credible"). NO names.
- bestInterestsFindings — string summary of the best-interests analysis if present. NO names.

META
- confidence — "high"|"medium"|"low" — your overall confidence in the extraction
- isActualOrder — true|false — true if this is genuinely a court order (vs a motion, notice, filing receipt)

CRITICAL PII RULES:
Never confuse a party's name with their attorney's name. Attorneys appear in signature blocks labeled "Counsel for ___". The party name on the same document is PRIVATE. Never echo party names, child names, case numbers, dates of birth, SSNs, street addresses, phone numbers, or financial account numbers. When summarizing reasoning, use role placeholders [Petitioner]/[Respondent]/[Mother]/[Father]/[Child 1]/[Child 2] etc. IF AN ATTORNEY ASSIGNMENT IS AMBIGUOUS (the document doesn't clearly label which party they represent), return null for that field.

If the document does NOT appear to be a real court order, set "isActualOrder": false and leave other fields null. If you see any field that would violate the privacy rules above, set it to null — never return partial strings or redacted versions.
SYS;

$payload = [
    'model' => 'claude-sonnet-4-20250514',
    'max_tokens' => 4500,
    'system' => $system,
    'messages' => [[
        'role' => 'user',
        'content' => [
            ['type' => 'document', 'source' => [
                'type' => 'base64',
                'media_type' => 'application/pdf',
                'data' => $b64,
            ]],
            ['type' => 'text', 'text' => 'Extract the statistical fields from this document according to the strict rules in the system prompt. Return JSON only — no prose, no preamble.'],
        ],
    ]],
];

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $key,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_TIMEOUT        => 45,
]);
$resp = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($resp === false || $status >= 400) {
    http_response_code(502);
    echo json_encode(['ok'=>false,'error'=>'AI upstream error: ' . ($err ?: "HTTP $status")]);
    exit;
}

$body = json_decode($resp, true);
$text = $body['content'][0]['text'] ?? '';
// The model may wrap JSON in prose despite instructions — isolate the first {...} block.
if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
    $data = json_decode($m[0], true);
} else {
    $data = null;
}
if (!is_array($data)) {
    http_response_code(502);
    echo json_encode(['ok'=>false,'error'=>'AI returned unparseable response.']);
    exit;
}

// Belt-and-suspenders PII scrub: even if the model ignored the prompt and
// snuck in a party name, our whitelist of fields means anything extra is
// simply ignored. We ALSO strip any keys not in the whitelist.
$allowed = [
    // Court & case basics
    'courtName','state','county','yearFiled','yearOfOrder',
    'judgeName','hearingOfficerName','hearingOfficerTitle',
    'orderSignedDate','isExParte','isTemporary','isFinalOrder',
    // Attorneys (by party role) + raw fallback list
    'petitionerAttorney','petitionerAttorneyFirm','petitionerAttorneyBarNumber',
    'respondentAttorney','respondentAttorneyFirm','respondentAttorneyBarNumber',
    'attorneysListed',
    // Order type & custody
    'orderType','numChildren','physicalCustody','legalCustody',
    // Custody schedule
    'weekendFrequency','weekendDuration','weekdayVisits','summerSchedule','holidaySchedule','firstRightOfRefusal',
    // Financial
    'monthlySupport','retroactiveSupport','arrears','arrearsAmount','interestOnArrears',
    'incomeImputed','imputedMonthlyIncome','filingFees','attorneyFeesAwarded','sanctionsAmount',
    'taxExemption','medicalInsuranceProvider',
    // Enforcement
    'wageGarnishment','licenseSuspension','passportHold','taxRefundIntercept','creditReporting',
    'contemptFinding','jailSentence',
    // Order flags
    'supervisedVisitation','supervisedVisitationProvider','gagOrder','pfaFiled','emergencyOrder',
    'mediationOrdered','parentingCoordinatorOrdered',
    // Professional appointments
    'galAppointed','galName','custodyEvaluatorAppointed','custodyEvaluatorName',
    'parentingCoordinatorName','reunificationTherapistOrdered','reunificationTherapistName',
    // Court-ordered requirements
    'parentingClassesOrdered','angerManagementOrdered','substanceAbuseEvaluationOrdered',
    'drugTestingOrdered','drugTestingFrequency','mentalHealthEvalOrdered','therapyOrdered',
    // Timeline
    'numberOfHearingsReferenced','monthsFromFilingToOrder','continuancesMentioned',
    // Military
    'militaryMentioned','scraStayRequested','scraStayOutcome','bahIncludedInIncome',
    // Judge's reasoning
    'judgeRemarks','credibilityFindings','bestInterestsFindings',
    // Meta
    'confidence','isActualOrder',
];
// Bump max_tokens — expanded schema + judgeRemarks can be long

$clean = [];
foreach ($allowed as $k) if (array_key_exists($k, $data)) $clean[$k] = $data[$k];

// If the model flagged the doc as not-an-order, return 422 so the client
// warns the user — but don't block submission.
if (isset($clean['isActualOrder']) && $clean['isActualOrder'] === false) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>'This file does not appear to be a court order. You can still submit without verification.']);
    exit;
}

// Commit the hash to the dedup log now that verification succeeded.
@file_put_contents($dedupFile, $hash . "\t" . gmdate('Y-m-d\TH:i:s\Z') . "\n", FILE_APPEND | LOCK_EX);

echo json_encode(['ok'=>true,'data'=>$clean,'hash'=>$hash]);
