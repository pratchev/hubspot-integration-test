<?php
/**
 * HubSpot Integration Test – single-file PHP app
 * Drop this into a folder and run:
 *   php -S localhost:8000
 * then open http://localhost:8000/hubspot-integration-test.php
 *
 * Requirements satisfied:
 * - Title “HubSpot Integration Test”
 * - Private app static token (hardcoded below)
 * - PHP, runs fine on macOS + VS Code
 * - Dropdown of forms (label “Forms”) from HubSpot
 * - When selected, shows “Form ID”
 * - Scrollable one-column grid of “Form Properties” (first 10 visible)
 * - “Form Data” grid: 25/page, prev/next/first/last, total pages & records
 * - Sorted by create date desc (submission time where available)
 * - Changing form refreshes all sections
 * - CarePatrol-like colors
 */

// ---------------------- CONFIG ----------------------
const HUBSPOT_BASE   = 'https://api.hubapi.com';

// Load environment variables from .env file if it exists
function loadEnv($filePath) {
    if (!file_exists($filePath)) {
        return;
    }
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue; // Skip comments
        }
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Load .env file
loadEnv(__DIR__ . '/.env');

// Set your HubSpot Private App token here or via environment variable
$hs_token = $_ENV['HUBSPOT_TOKEN'] ?? getenv('HUBSPOT_TOKEN') ?? 'YOUR_HUBSPOT_TOKEN_HERE';
define('HS_TOKEN', $hs_token);
// ----------------------------------------------------

function hs_request(string $method, string $url, array $query = [], $body = null) {
    $ch = curl_init();
    if (!empty($query)) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
    }
    $headers = [
        'Authorization: Bearer ' . HS_TOKEN,
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
    }
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err) {
        return ['ok' => false, 'code' => 0, 'error' => $err];
    }
    $data = json_decode($resp, true);
    return ['ok' => ($code >= 200 && $code < 300), 'code' => $code, 'data' => $data, 'raw' => $resp];
}

/**
 * Get forms: try v3 first, fall back to legacy v2 if needed.
 * v3: GET /marketing/v3/forms
 * v2: GET /forms/v2/forms
 */
function get_forms(): array {
    // Check if token is set
    if (HS_TOKEN === 'YOUR_HUBSPOT_TOKEN_HERE' || empty(HS_TOKEN)) {
        error_log('HubSpot token not configured');
        return [];
    }
    
    // Try v3
    $r = hs_request('GET', HUBSPOT_BASE . '/marketing/v3/forms', ['limit' => 250]);
    if ($r['ok'] && isset($r['data']['results'])) {
        // Normalize: id, name, fields
        $forms = [];
        foreach ($r['data']['results'] as $f) {
            $id = $f['id'] ?? $f['guid'] ?? null;
            $name = $f['name'] ?? 'Untitled form';
            $fields = [];
            // v3 field schema lives under 'formFieldGroups' similar to v2; handle both
            if (isset($f['formFieldGroups']) && is_array($f['formFieldGroups'])) {
                foreach ($f['formFieldGroups'] as $g) {
                    if (!empty($g['fields'])) {
                        foreach ($g['fields'] as $fld) {
                            $fields[] = [
                                'name' => $fld['name'] ?? '',
                                'label' => $fld['label'] ?? ($fld['name'] ?? ''),
                                'type'  => $fld['fieldType'] ?? ($fld['type'] ?? ''),
                            ];
                        }
                    }
                }
            }
            $forms[] = ['id' => $id, 'name' => $name, 'fields' => $fields, 'raw' => $f];
        }
        return $forms;
    }

    // Log v3 error for debugging
    error_log('HubSpot v3 forms API failed: ' . json_encode($r));

    // Fall back to v2 (legacy)
    $r2 = hs_request('GET', HUBSPOT_BASE . '/forms/v2/forms');
    $forms = [];
    if ($r2['ok'] && is_array($r2['data'])) {
        foreach ($r2['data'] as $f) {
            $id = $f['guid'] ?? null;
            $name = $f['name'] ?? 'Untitled form';
            $fields = [];
            if (isset($f['formFieldGroups'])) {
                foreach ($f['formFieldGroups'] as $g) {
                    foreach (($g['fields'] ?? []) as $fld) {
                        $fields[] = [
                            'name' => $fld['name'] ?? '',
                            'label' => $fld['label'] ?? ($fld['name'] ?? ''),
                            'type'  => $fld['fieldType'] ?? ($fld['type'] ?? ''),
                        ];
                    }
                }
            }
            $forms[] = ['id' => $id, 'name' => $name, 'fields' => $fields, 'raw' => $f];
        }
    } else {
        // Log v2 error for debugging
        error_log('HubSpot v2 forms API also failed: ' . json_encode($r2));
    }
    return $forms;
}

/**
 * Get details for one form (fields), trying v3 then v2.
 */
function get_form_details(string $formId): array {
    // v3
    $r = hs_request('GET', HUBSPOT_BASE . '/marketing/v3/forms/' . urlencode($formId));
    if ($r['ok'] && isset($r['data'])) {
        $fields = [];
        if (!empty($r['data']['formFieldGroups'])) {
            foreach ($r['data']['formFieldGroups'] as $g) {
                foreach (($g['fields'] ?? []) as $fld) {
                    $fields[] = [
                        'name' => $fld['name'] ?? '',
                        'label' => $fld['label'] ?? ($fld['name'] ?? ''),
                        'type'  => $fld['fieldType'] ?? ($fld['type'] ?? ''),
                    ];
                }
            }
        }
        return ['id' => $r['data']['id'] ?? $formId, 'name' => $r['data']['name'] ?? '', 'fields' => $fields];
    }
    // v2
    $r2 = hs_request('GET', HUBSPOT_BASE . '/forms/v2/forms/' . urlencode($formId));
    if ($r2['ok'] && isset($r2['data'])) {
        $fields = [];
        foreach (($r2['data']['formFieldGroups'] ?? []) as $g) {
            foreach (($g['fields'] ?? []) as $fld) {
                $fields[] = [
                    'name' => $fld['name'] ?? '',
                    'label' => $fld['label'] ?? ($fld['name'] ?? ''),
                    'type'  => $fld['fieldType'] ?? ($fld['type'] ?? ''),
                ];
            }
        }
        return ['id' => $formId, 'name' => $r2['data']['name'] ?? '', 'fields' => $fields];
    }
    return ['id' => $formId, 'name' => '', 'fields' => []];
}

/**
 * Get submissions for a form (25/page) using:
 * GET /crm/v3/extensions/forms/{formId}/submissions?limit=25&after=<cursor>
 * Returns rows + paging info. Sorted by submittedAt desc if API supports; otherwise sort client-side.
 */
function get_form_submissions(string $formId, int $limit = 25, ?string $after = null): array {
    $query = ['limit' => $limit];
    if ($after) $query['after'] = $after;

    $url = HUBSPOT_BASE . '/crm/v3/extensions/forms/' . urlencode($formId) . '/submissions';
    $r   = hs_request('GET', $url, $query);

    if (!$r['ok']) {
        return [
            'supported' => false,
            'message'   => 'Form submissions API not available in this account or token scope. You can still validate forms & fields.',
            'rows'      => [],
            'columns'   => [],
            'paging'    => ['next' => null, 'prev' => null, 'last' => null, 'total' => 0],
            'raw'       => $r
        ];
    }

    $data = $r['data'] ?? [];
    $results = $data['results'] ?? $data['submissions'] ?? [];
    $paging  = $data['paging']['next']['after'] ?? null;
    $total   = $data['total'] ?? null; // not always provided

    // Normalize submissions to rows: each row = property => value, plus createdate/lastmodifieddate
    $rows = [];
    $allKeys = [];
    foreach ($results as $sub) {
        $vals = [];

        // Common patterns seen in practice
        if (isset($sub['values']) && is_array($sub['values'])) {
            foreach ($sub['values'] as $v) {
                // v can be ['name'=>'email','value'=>'a@b.com'] or ['name'=>'firstname','values'=>['John']]
                $k = $v['name'] ?? '';
                $value = $v['value'] ?? (isset($v['values']) ? implode(', ', (array)$v['values']) : '');
                if ($k !== '') $vals[$k] = $value;
            }
        } elseif (isset($sub['properties']) && is_array($sub['properties'])) {
            foreach ($sub['properties'] as $k => $v) $vals[$k] = is_array($v) ? json_encode($v) : $v;
        }

        // Dates
        $created = $sub['submittedAt'] ?? $sub['createdAt'] ?? null;
        $updated = $sub['updatedAt']   ?? $created;

        $vals['createdate'] = $created ? date('c', strtotime($created)) : '';
        $vals['lastmodifieddate'] = $updated ? date('c', strtotime($updated)) : '';

        $rows[] = $vals;
        $allKeys = array_unique(array_merge($allKeys, array_keys($vals)));
    }

    // Ensure createdate is first, lastmodifieddate second; keep others after
    $columns = array_values(array_unique(array_merge(['createdate', 'lastmodifieddate'], array_diff($allKeys, ['createdate','lastmodifieddate']))));

    // Sort rows by createdate desc (client-side, just in case)
    usort($rows, function($a, $b) {
        return strcmp($b['createdate'] ?? '', $a['createdate'] ?? '');
    });

    return [
        'supported' => true,
        'rows'      => $rows,
        'columns'   => $columns,
        'paging'    => [
            'next' => $data['paging']['next']['after'] ?? null,
            'prev' => $_GET['after'] ?? null, // track we came from
            'total' => $total,
        ],
        'raw'       => $data
    ];
}

// ---------------- AJAX API ----------------
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    switch ($_GET['action']) {
        case 'listForms':
            $forms = get_forms();
            // Add debug info if no forms found
            if (empty($forms)) {
                echo json_encode([
                    'forms' => [],
                    'debug' => [
                        'token_set' => HS_TOKEN !== 'YOUR_HUBSPOT_TOKEN_HERE',
                        'token_length' => strlen(HS_TOKEN),
                        'hubspot_base' => HUBSPOT_BASE
                    ]
                ]);
            } else {
                echo json_encode(['forms' => $forms]);
            }
            break;
        case 'formDetails':
            $id = $_GET['id'] ?? '';
            echo json_encode(get_form_details($id));
            break;
        case 'submissions':
            $id = $_GET['id'] ?? '';
            $after = $_GET['after'] ?? null;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;
            echo json_encode(get_form_submissions($id, $limit, $after));
            break;
        default:
            echo json_encode(['error' => 'Unknown action']);
    }
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>HubSpot Integration Test</title>
<style>
  :root{
    /* CarePatrol-esque theme */
    --cp-navy:#1E345D;
    --cp-red:#BC201E;
    --cp-accent:#099eda;
    --cp-bg:#f6f8fb;
    --cp-text:#1a1f2b;
    --cp-muted:#6b7280;
    --cp-border:#e5e7eb;
    --radius:14px;
  }
  *{box-sizing:border-box}
  body{
    margin:0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    color:var(--cp-text); background:var(--cp-bg);
  }
  header{
    background:linear-gradient(135deg, var(--cp-navy), #0b1f3f);
    color:#fff; padding:28px 20px; border-bottom:4px solid var(--cp-red);
  }
  h1{margin:0; font-size:22px; letter-spacing:.4px}
  .container{max-width:1200px; margin:20px auto; padding:0 16px}
  .card{
    background:#fff; border:1px solid var(--cp-border); border-radius: var(--radius);
    box-shadow: 0 6px 16px rgba(10,18,38,.06);
    margin-bottom:16px; padding:16px;
  }
  label{font-weight:600; color:var(--cp-navy)}
  select{
    width:100%; padding:10px 12px; border:1px solid var(--cp-border); border-radius:10px;
    font-size:14px; outline:none;
  }
  .row{display:grid; grid-template-columns: 1fr 1fr; gap:16px; align-items:start}
  .form-field{display:flex; flex-direction:column; gap:6px}
  .pill{
    display:inline-block; padding:10px 12px; background:#f1f5f9; border:1px solid var(--cp-border);
    border-radius:10px; font-size:14px; color:var(--cp-navy); min-height:38px; display:flex; align-items:center;
  }
  .grid-title{font-weight:700; color:var(--cp-navy); margin:8px 0 12px}
  .props{
    max-height:240px; overflow:auto; border:1px solid var(--cp-border); border-radius:10px; padding:8px;
  }
  .prop-row{padding:8px 6px; border-bottom:1px dashed var(--cp-border)}
  .prop-row:last-child{border-bottom:0}
  .prop-name{font-weight:600; color:var(--cp-text)}
  .prop-type{color:var(--cp-muted); font-size:12px}
  table{
    width:100%; border-collapse:separate; border-spacing:0; border:1px solid var(--cp-border);
    border-radius:10px; overflow:hidden; font-size:14px;
  }
  thead{background:var(--cp-navy); color:#fff; position:sticky; top:0}
  th, td{padding:10px 12px; border-bottom:1px solid var(--cp-border)}
  tbody tr:nth-child(even){background:#fafafa}
  .toolbar{display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:10px}
  .btn{
    appearance:none; border:1px solid var(--cp-navy); background:#fff; color:var(--cp-navy);
    padding:8px 10px; border-radius:10px; cursor:pointer; font-weight:600;
  }
  .btn[disabled]{opacity:.45; cursor:not-allowed}
  .btn.primary{background:var(--cp-red); border-color:var(--cp-red); color:#fff}
  .stats{color:var(--cp-muted); font-size:13px}
  .inline{display:flex; gap:12px; align-items:center}
  .badge{background:var(--cp-accent); color:#fff; padding:3px 8px; border-radius:999px; font-size:12px}
  .hint{color:var(--cp-muted); font-size:13px}
  @media (max-width:900px){ .row{grid-template-columns:1fr} }
</style>
</head>
<body>
<header>
  <h1>HubSpot Integration Test</h1>
</header>

<div class="container">
  <div class="card">
    <div class="row">
      <div class="form-field">
        <label for="formsSelect">Forms</label>
        <select id="formsSelect" aria-label="Forms"></select>
        <div class="hint" id="formsHint" style="margin-top:6px;"></div>
      </div>
      <div class="form-field">
        <label for="formIdPill">Form ID</label>
        <div class="pill" id="formIdPill">—</div>
      </div>
    </div>
  </div>

  <div class="card" id="propsCard" style="display:none">
    <div class="grid-title">Form Properties <span class="badge" id="propCount">0</span></div>
    <div class="props" id="propsList"></div>
  </div>

  <div class="card" id="dataCard" style="display:none">
    <div class="grid-title">Form Data</div>
    <div class="hint" id="subsHint" style="margin-bottom:8px; display:none;"></div>
    <div style="overflow:auto; max-height:60vh">
      <table id="dataTable">
        <thead><tr id="theadRow"></tr></thead>
        <tbody id="tbodyRows"></tbody>
      </table>
    </div>
    <div class="toolbar">
      <div class="inline">
        <button class="btn" id="firstBtn">⏮ First</button>
        <button class="btn" id="prevBtn">◀ Prev</button>
        <button class="btn" id="nextBtn">Next ▶</button>
        <button class="btn" id="lastBtn">⏭ Last</button>
      </div>
      <div class="stats" id="statsText">Page 1 of 1 • 0 records</div>
    </div>
  </div>
</div>

<script>
const qs = (s)=>document.querySelector(s);
const qsa = (s)=>Array.from(document.querySelectorAll(s));
const formsSelect = qs('#formsSelect');
const formIdPill  = qs('#formIdPill');
const propsCard   = qs('#propsCard');
const propCount   = qs('#propCount');
const propsList   = qs('#propsList');
const dataCard    = qs('#dataCard');
const theadRow    = qs('#theadRow');
const tbodyRows   = qs('#tbodyRows');
const statsText   = qs('#statsText');
const subsHint    = qs('#subsHint');
const formsHint   = qs('#formsHint');

let currentFormId = null;
let currentColumns = [];
let paging = { next: null, prev: null, total: null, cursorStack: [] };

async function api(action, params={}){
  const url = new URL(location.href);
  url.search = ''; // ensure we call same file without page params
  url.hash = '';
  url.searchParams.set('action', action);
  Object.entries(params).forEach(([k,v])=> v!=null && url.searchParams.set(k, v));
  const r = await fetch(url.toString(), { headers: { 'Accept':'application/json' }});
  if (!r.ok) throw new Error('HTTP '+r.status);
  return r.json();
}

function renderProps(fields){
  propsList.innerHTML = '';
  const max = fields.length;
  propCount.textContent = max;
  fields.forEach((f, i) => {
    const row = document.createElement('div');
    row.className = 'prop-row';
    row.innerHTML = `<div class="prop-name">${f.label || f.name}</div><div class="prop-type">${f.name}${f.type? ' • '+f.type : ''}</div>`;
    propsList.appendChild(row);
  });
  propsCard.style.display = 'block';
}

function renderTable(columns, rows){
  currentColumns = columns;
  theadRow.innerHTML = '';
  columns.forEach(c=>{
    const th = document.createElement('th');
    th.textContent = c;
    theadRow.appendChild(th);
  });
  tbodyRows.innerHTML = '';
  rows.forEach(r=>{
    const tr = document.createElement('tr');
    columns.forEach(c=>{
      const td = document.createElement('td');
      td.textContent = (r[c] ?? '');
      tr.appendChild(td);
    });
    tbodyRows.appendChild(tr);
  });
  dataCard.style.display = 'block';
}

async function loadForms(){
  formsHint.textContent = 'Loading forms…';
  try {
    const data = await api('listForms');
    const forms = data.forms || [];
    
    // Show debug info if no forms and debug data is available
    if (forms.length === 0 && data.debug) {
      let debugMsg = 'No forms found. ';
      if (!data.debug.token_set) {
        debugMsg += 'HubSpot token not configured.';
      } else {
        debugMsg += `Token configured (${data.debug.token_length} chars). Check token permissions.`;
      }
      formsHint.textContent = debugMsg;
      formsHint.style.color = 'var(--cp-red)';
      return;
    }
    
    // Sort forms alphabetically by name
    forms.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
    formsSelect.innerHTML = '';
    // Add default empty option
    const defaultOpt = document.createElement('option');
    defaultOpt.value = '';
    defaultOpt.textContent = 'Select a form...';
    formsSelect.appendChild(defaultOpt);
    
    forms.forEach(f=>{
      const opt = document.createElement('option');
      opt.value = f.id;
      opt.textContent = f.name;
      opt.dataset.fields = JSON.stringify(f.fields || []);
      formsSelect.appendChild(opt);
    });
    formsHint.textContent = `${forms.length} forms loaded`;
    formsHint.style.color = 'var(--cp-muted)';
    
    // Don't auto-select first form, let user choose
    formsSelect.value = '';
    onFormChange();
  } catch (e) {
    console.error('Error loading forms:', e);
    formsHint.textContent = 'Error loading forms: ' + e.message;
    formsHint.style.color = 'var(--cp-red)';
  }
}

async function onFormChange(){
  const id = formsSelect.value;
  if (!id) {
    // Hide cards if no form selected
    propsCard.style.display = 'none';
    dataCard.style.display = 'none';
    formIdPill.textContent = '—';
    return;
  }
  
  currentFormId = id;
  formIdPill.textContent = id;

  // Fields: if list API didn't include them, fetch details
  let fields = [];
  try {
    const selectedOption = formsSelect.selectedOptions[0];
    if (selectedOption && selectedOption.dataset.fields) {
      fields = JSON.parse(selectedOption.dataset.fields);
    }
  } catch (e) {
    console.warn('Error parsing fields from select option:', e);
  }
  
  // If no fields from the dropdown data, fetch them via API
  if (!fields || fields.length === 0){
    try {
      const fd = await api('formDetails', { id });
      fields = fd.fields || [];
    } catch (e) {
      console.error('Error fetching form details:', e);
      fields = [];
    }
  }
  
  renderProps(fields);

  // Submissions page 1
  paging = { next:null, prev:null, total:null, cursorStack:[] };
  await loadSubmissions(null, true);
}

function updatePagerUI(totalKnown, recordCountOnPage){
  // We don’t always get total; show what we can
  const pageNum = (paging.cursorStack.length || 0) + 1;
  const totalPages = totalKnown ? Math.max(1, Math.ceil(totalKnown / 25)) : '—';
  const totalRecs  = totalKnown ?? '—';
  statsText.textContent = `Page ${pageNum} of ${totalPages} • ${totalRecs} records`;

  qs('#prevBtn').disabled = paging.cursorStack.length === 0;
  qs('#firstBtn').disabled = paging.cursorStack.length === 0;
  qs('#nextBtn').disabled  = !paging.next;
  qs('#lastBtn').disabled  = !totalKnown || !paging.next; // naive until total is known
}

async function loadSubmissions(after=null, reset=false){
  tbodyRows.innerHTML = '<tr><td>Loading…</td></tr>';
  const data = await api('submissions', { id: currentFormId, limit:25, after });
  if (!data.supported){
    subsHint.style.display = 'block';
    subsHint.textContent = data.message || 'Submissions not available.';
    renderTable(['createdate','lastmodifieddate'], []);
    updatePagerUI(null, 0);
    return;
  }

  subsHint.style.display = 'none';
  renderTable(data.columns, data.rows);
  paging.next = data.paging?.next || null;
  paging.total = data.paging?.total || null;

  if (reset) paging.cursorStack = [];
  updatePagerUI(paging.total, data.rows.length);
}

// Pager events
qs('#nextBtn').addEventListener('click', async ()=>{
  if (!paging.next) return;
  paging.cursorStack.push(paging.next); // push current end id as history marker
  await loadSubmissions(paging.next);
});
qs('#prevBtn').addEventListener('click', async ()=>{
  if (!paging.cursorStack.length) return;
  // Pop last cursor and compute the one before that to pass as 'after'
  paging.cursorStack.pop();
  const prevCursor = paging.cursorStack.length ? paging.cursorStack[paging.cursorStack.length - 1] : null;
  await loadSubmissions(prevCursor);
});
qs('#firstBtn').addEventListener('click', async ()=>{
  paging.cursorStack = [];
  await loadSubmissions(null);
});
qs('#lastBtn').addEventListener('click', async ()=>{
  // Best-effort: step forward until no next (may require multiple clicks)
  if (paging.next) {
    paging.cursorStack.push(paging.next);
    await loadSubmissions(paging.next);
  }
});

formsSelect.addEventListener('change', onFormChange);
loadForms().catch(e=>{ formsHint.textContent = 'Failed to load forms: ' + e.message; });
</script>
</body>
</html>
