<?php
/**
 * HubDB Integration Test – single-file PHP app
 * Drop this into a folder and run:
 *   php -S localhost:8000
 * then open http://localhost:8000/hubdb-integration-test.php
 *
 * Requirements satisfied:
 * - Title "HubDB Integration Test"
 * - Private app static token (same as hubspot-integration-test.php)
 * - PHP, runs fine on macOS + VS Code
 * - Dropdown of HubDB tables from /cms/v3/hubdb/tables
 * - When selected, shows "Table ID"
 * - Scrollable list of "Table Fields" (first 5 visible)
 * - "Table Data" grid: 25/page, prev/next/first/last, total pages & records
 * - Changing table refreshes all sections
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
 * Get HubDB tables using GET /cms/v3/hubdb/tables
 */
function get_hubdb_tables(): array {
    // Check if token is set
    if (HS_TOKEN === 'YOUR_HUBSPOT_TOKEN_HERE' || empty(HS_TOKEN)) {
        error_log('HubSpot token not configured');
        return [];
    }
    
    $r = hs_request('GET', HUBSPOT_BASE . '/cms/v3/hubdb/tables', ['limit' => 250]);
    if ($r['ok'] && isset($r['data']['results'])) {
        $tables = [];
        foreach ($r['data']['results'] as $table) {
            $id = $table['id'] ?? null;
            $name = $table['name'] ?? $table['label'] ?? 'Untitled table';
            $rowCount = $table['rowCount'] ?? 0;
            $columns = $table['columns'] ?? [];
            
            // Process columns/fields
            $fields = [];
            foreach ($columns as $col) {
                $fields[] = [
                    'name' => $col['name'] ?? '',
                    'label' => $col['label'] ?? ($col['name'] ?? ''),
                    'type' => $col['type'] ?? '',
                    'id' => $col['id'] ?? ''
                ];
            }
            
            $tables[] = [
                'id' => $id,
                'name' => $name,
                'rowCount' => $rowCount,
                'fields' => $fields,
                'raw' => $table
            ];
        }
        return $tables;
    }

    // Log error for debugging
    error_log('HubDB tables API failed: ' . json_encode($r));
    return [];
}

/**
 * Get details for one HubDB table including its schema
 */
function get_hubdb_table_details(string $tableId): array {
    error_log('Getting table details for table ID: ' . $tableId);
    
    $r = hs_request('GET', HUBSPOT_BASE . '/cms/v3/hubdb/tables/' . urlencode($tableId));
    error_log('Table details response: ' . json_encode($r));
    
    if ($r['ok'] && isset($r['data'])) {
        $tableData = $r['data'];
        $fields = [];
        
        // Extract columns/fields
        if (!empty($tableData['columns'])) {
            foreach ($tableData['columns'] as $col) {
                $fields[] = [
                    'name' => $col['name'] ?? '',
                    'label' => $col['label'] ?? ($col['name'] ?? ''),
                    'type' => $col['type'] ?? '',
                    'id' => $col['id'] ?? ''
                ];
            }
        }
        
        return [
            'id' => $tableData['id'] ?? $tableId,
            'name' => $tableData['name'] ?? $tableData['label'] ?? '',
            'rowCount' => $tableData['rowCount'] ?? 0,
            'fields' => $fields
        ];
    }
    
    error_log('No table details found for table ID: ' . $tableId);
    return ['id' => $tableId, 'name' => '', 'rowCount' => 0, 'fields' => []];
}

/**
 * Get rows from a HubDB table with pagination
 * GET /cms/v3/hubdb/tables/{tableId}/rows
 */
function get_hubdb_table_rows(string $tableId, int $limit = 25, int $offset = 0): array {
    $query = ['limit' => $limit, 'offset' => $offset];
    
    $url = HUBSPOT_BASE . '/cms/v3/hubdb/tables/' . urlencode($tableId) . '/rows';
    $r = hs_request('GET', $url, $query);

    if (!$r['ok']) {
        return [
            'supported' => false,
            'message' => 'HubDB table rows API not available or table not found.',
            'rows' => [],
            'columns' => [],
            'paging' => [
                'offset' => $offset,
                'limit' => $limit,
                'hasNext' => false,
                'hasPrev' => false,
                'currentPage' => 1,
                'total' => 0,
                'totalPages' => 1
            ],
            'raw' => $r
        ];
    }

    $data = $r['data'] ?? [];
    $results = $data['results'] ?? [];
    $total = $data['total'] ?? count($results);
    
    // Normalize rows: extract values from each row
    $rows = [];
    $allKeys = [];
    
    foreach ($results as $row) {
        $vals = [];
        
        // Add the row ID
        $vals['id'] = $row['id'] ?? '';
        
        // Extract values from the row
        if (isset($row['values']) && is_array($row['values'])) {
            foreach ($row['values'] as $key => $value) {
                $vals[$key] = is_array($value) ? json_encode($value) : $value;
            }
        }
        
        // Add created/updated timestamps if available
        if (isset($row['createdAt'])) {
            $vals['createdAt'] = $row['createdAt'];
        }
        if (isset($row['updatedAt'])) {
            $vals['updatedAt'] = $row['updatedAt'];
        }
        
        $rows[] = $vals;
        $allKeys = array_unique(array_merge($allKeys, array_keys($vals)));
    }

    // Ensure ID is first, then other columns
    $columns = array_values(array_unique(array_merge(['id'], array_diff($allKeys, ['id']))));

    // Calculate pagination info
    $currentPage = floor($offset / $limit) + 1;
    $totalPages = $total > 0 ? ceil($total / $limit) : 1;
    $hasNext = ($offset + $limit) < $total;
    $hasPrev = $offset > 0;

    return [
        'supported' => true,
        'rows' => $rows,
        'columns' => $columns,
        'paging' => [
            'offset' => $offset,
            'limit' => $limit,
            'hasNext' => $hasNext,
            'hasPrev' => $hasPrev,
            'currentPage' => $currentPage,
            'total' => $total,
            'totalPages' => $totalPages,
            'recordCount' => count($rows)
        ],
        'raw' => $data
    ];
}

// ---------------- AJAX API ----------------
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    switch ($_GET['action']) {
        case 'listTables':
            $tables = get_hubdb_tables();
            if (empty($tables)) {
                echo json_encode([
                    'tables' => [],
                    'debug' => [
                        'token_set' => HS_TOKEN !== 'YOUR_HUBSPOT_TOKEN_HERE',
                        'token_length' => strlen(HS_TOKEN),
                        'hubspot_base' => HUBSPOT_BASE
                    ]
                ]);
            } else {
                echo json_encode(['tables' => $tables]);
            }
            break;
        case 'tableDetails':
            $id = $_GET['id'] ?? '';
            $result = get_hubdb_table_details($id);
            $result['debug'] = [
                'requested_id' => $id,
                'field_count' => count($result['fields'] ?? []),
                'has_fields' => !empty($result['fields'])
            ];
            echo json_encode($result);
            break;
        case 'tableRows':
            $id = $_GET['id'] ?? '';
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;
            echo json_encode(get_hubdb_table_rows($id, $limit, $offset));
            break;
        case 'debug':
            echo json_encode([
                'token_configured' => HS_TOKEN !== 'YOUR_HUBSPOT_TOKEN_HERE',
                'token_length' => strlen(HS_TOKEN),
                'php_version' => PHP_VERSION,
                'curl_available' => function_exists('curl_init')
            ]);
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
<title>HubDB Integration Test</title>
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
  .container{max-width:90%; width:90%; margin:20px auto; padding:0 16px}
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
  .fields{
    max-height:240px; overflow:auto; border:1px solid var(--cp-border); border-radius:10px; padding:8px;
  }
  .field-row{padding:8px 6px; border-bottom:1px dashed var(--cp-border)}
  .field-row:last-child{border-bottom:0}
  .field-name{font-weight:600; color:var(--cp-text)}
  .field-type{color:var(--cp-muted); font-size:12px}
  table{
    width:100%; border-collapse:separate; border-spacing:0; border:1px solid var(--cp-border);
    border-radius:10px; overflow:hidden; font-size:14px; table-layout:fixed;
  }
  thead{background:var(--cp-navy); color:#fff; position:sticky; top:0}
  th, td{padding:10px 12px; border-bottom:1px solid var(--cp-border); text-align: left;}
  th{font-weight: 600; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; white-space: normal; word-wrap: break-word; hyphens: auto; line-height: 1.3;}
  tbody tr:nth-child(even){background:#fafafa}
  tbody tr:hover{background:#f0f4f8}
  td{vertical-align: top;}
  .table-container{overflow-x:auto; max-width:100%;}
  .toolbar{display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:10px; flex-wrap: wrap;}
  .btn{
    appearance:none; border:1px solid var(--cp-navy); background:#fff; color:var(--cp-navy);
    padding:8px 12px; border-radius:10px; cursor:pointer; font-weight:600; font-size: 13px;
    transition: all 0.2s ease;
  }
  .btn:hover:not([disabled]){background:var(--cp-navy); color:#fff;}
  .btn[disabled]{opacity:.45; cursor:not-allowed; background:#f8f9fa;}
  .btn.primary{background:var(--cp-red); border-color:var(--cp-red); color:#fff}
  .stats{color:var(--cp-muted); font-size:13px; font-weight: 500;}
  .inline{display:flex; gap:8px; align-items:center}
  .badge{background:var(--cp-accent); color:#fff; padding:3px 8px; border-radius:999px; font-size:12px}
  .hint{color:var(--cp-muted); font-size:13px}
  .notice{padding:12px; border-radius:8px; margin-bottom:16px; font-weight:500}
  .notice-warning{background:#fff3cd; border:1px solid #ffeaa7; color:#856404}
  .notice-error{background:#f8d7da; border:1px solid #f5c6cb; color:#721c24}
  @media (max-width:900px){ 
    .row{grid-template-columns:1fr}
    .container{padding:0 12px; width:95%}
  }
  @media (max-width:600px){
    th, td{padding:6px 4px; font-size:11px}
    th{font-size:10px; line-height:1.2}
    .container{width:98%}
    .stats{font-size:12px}
  }
</style>
</head>
<body>
<header>
  <h1>HubDB Integration Test</h1>
</header>

<div class="container">
  <div class="card">
    <div class="row">
      <div class="form-field">
        <label for="tablesSelect">Tables</label>
        <select id="tablesSelect" aria-label="Tables"></select>
        <div class="hint" id="tablesHint" style="margin-top:6px;"></div>
      </div>
      <div class="form-field">
        <label for="tableIdPill">Table ID</label>
        <div class="pill" id="tableIdPill">—</div>
      </div>
    </div>
  </div>

  <div class="card" id="fieldsCard" style="display:none">
    <div class="grid-title">Table Fields <span class="badge" id="fieldCount">0</span></div>
    <div class="fields" id="fieldsList"></div>
  </div>

  <!-- Table rows notice/error message -->
  <div class="hint" id="rowsHint" style="display:none;"></div>

  <div class="card" id="dataCard" style="display:none">
    <div class="grid-title">Table Data</div>
    <div class="table-container">
      <div style="overflow:auto; max-height:70vh">
        <table id="dataTable">
          <thead><tr id="theadRow"></tr></thead>
          <tbody id="tbodyRows"></tbody>
        </table>
      </div>
    </div>
    <div class="toolbar">
      <div class="inline">
        <button class="btn" id="firstBtn">⏮ First</button>
        <button class="btn" id="prevBtn">◀ Prev</button>
        <button class="btn" id="nextBtn">Next ▶</button>
        <button class="btn" id="lastBtn">⏭ Last</button>
      </div>
      <div class="stats" id="statsText">Page 1 • 0 records on this page</div>
    </div>
  </div>
</div>

<script>
const qs = (s)=>document.querySelector(s);
const qsa = (s)=>Array.from(document.querySelectorAll(s));
const tablesSelect = qs('#tablesSelect');
const tableIdPill  = qs('#tableIdPill');
const fieldsCard   = qs('#fieldsCard');
const fieldCount   = qs('#fieldCount');
const fieldsList   = qs('#fieldsList');
const dataCard     = qs('#dataCard');
const theadRow     = qs('#theadRow');
const tbodyRows    = qs('#tbodyRows');
const statsText    = qs('#statsText');
const rowsHint     = qs('#rowsHint');
const tablesHint   = qs('#tablesHint');

let currentTableId = null;
let currentColumns = [];
let paging = { 
  offset: 0,
  limit: 25,
  total: null,
  totalPages: null,
  currentPage: 1,
  recordCount: 0
};

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

function renderFields(fields){
  fieldsList.innerHTML = '';
  const validFields = (fields || []).filter(f => f && (f.name || f.label));
  const max = validFields.length;
  fieldCount.textContent = max;
  
  if (max === 0) {
    const noFieldsMsg = document.createElement('div');
    noFieldsMsg.className = 'field-row';
    noFieldsMsg.innerHTML = `<div class="field-name" style="color: var(--cp-muted); font-style: italic;">No table fields found</div><div class="field-type" style="color: var(--cp-muted); font-size: 12px;">This might indicate that the HubSpot token is not configured or the table has no fields.</div>`;
    fieldsList.appendChild(noFieldsMsg);
  } else {
    validFields.forEach((f, i) => {
      const row = document.createElement('div');
      row.className = 'field-row';
      const label = f.label || f.name || 'Unnamed field';
      const name = f.name || '';
      const type = f.type || '';
      const id = f.id || '';
      row.innerHTML = `<div class="field-name">${label}</div><div class="field-type">${name}${type ? ' • '+type : ''}${id ? ' • ID: '+id : ''}</div>`;
      fieldsList.appendChild(row);
    });
  }
  fieldsCard.style.display = 'block';
}

function renderTable(columns, rows){
  currentColumns = columns;
  theadRow.innerHTML = '';
  columns.forEach(c=>{
    const th = document.createElement('th');
    // Capitalize and format column headers
    let displayName = c;
    if (c === 'id') displayName = 'Row ID';
    else if (c === 'createdAt') displayName = 'Created At';
    else if (c === 'updatedAt') displayName = 'Updated At';
    else displayName = c.charAt(0).toUpperCase() + c.slice(1);
    
    th.textContent = displayName;
    theadRow.appendChild(th);
  });
  
  tbodyRows.innerHTML = '';
  rows.forEach(r=>{
    const tr = document.createElement('tr');
    columns.forEach(c=>{
      const td = document.createElement('td');
      let value = r[c] ?? '';
      
      // Format timestamps for better readability
      if ((c === 'createdAt' || c === 'updatedAt') && value) {
        try {
          const date = new Date(value);
          if (!isNaN(date.getTime())) {
            value = date.toLocaleString();
          }
        } catch (e) {
          // Keep original value if date parsing fails
        }
      }
      
      td.textContent = value;
      
      // Add special styling for date columns
      if (c === 'createdAt' || c === 'updatedAt') {
        td.style.fontSize = '13px';
        td.style.color = 'var(--cp-muted)';
      }
      
      tr.appendChild(td);
    });
    tbodyRows.appendChild(tr);
  });
  dataCard.style.display = 'block';
}

async function loadTables(){
  tablesHint.textContent = 'Loading tables…';
  try {
    const data = await api('listTables');
    const tables = data.tables || [];
    
    // Show debug info if no tables and debug data is available
    if (tables.length === 0 && data.debug) {
      let debugMsg = 'No tables found. ';
      if (!data.debug.token_set) {
        debugMsg += 'HubSpot token not configured. Please set HUBSPOT_TOKEN in .env file or edit the PHP file directly.';
      } else {
        debugMsg += `Token configured (${data.debug.token_length} chars). Check token permissions for HubDB access.`;
      }
      tablesHint.textContent = debugMsg;
      tablesHint.style.color = 'var(--cp-red)';
      return;
    }
    
    // Sort tables alphabetically by name
    tables.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
    tablesSelect.innerHTML = '';
    // Add default empty option
    const defaultOpt = document.createElement('option');
    defaultOpt.value = '';
    defaultOpt.textContent = 'Select a table...';
    tablesSelect.appendChild(defaultOpt);
    
    tables.forEach(t=>{
      const opt = document.createElement('option');
      opt.value = t.id;
      opt.textContent = `${t.name} (${t.rowCount} rows)`;
      opt.dataset.fields = JSON.stringify(t.fields || []);
      opt.dataset.rowCount = t.rowCount || 0;
      tablesSelect.appendChild(opt);
    });
    tablesHint.textContent = `${tables.length} tables loaded`;
    tablesHint.style.color = 'var(--cp-muted)';
    
    // Don't auto-select first table, let user choose
    tablesSelect.value = '';
    onTableChange();
  } catch (e) {
    console.error('Error loading tables:', e);
    tablesHint.textContent = 'Error loading tables: ' + e.message;
    tablesHint.style.color = 'var(--cp-red)';
  }
}

async function onTableChange(){
  const id = tablesSelect.value;
  if (!id) {
    // Hide cards if no table selected
    fieldsCard.style.display = 'none';
    dataCard.style.display = 'none';
    rowsHint.style.display = 'none';
    tableIdPill.textContent = '—';
    return;
  }
  
  currentTableId = id;
  tableIdPill.textContent = id;

  // Try to get fields from the dropdown data first (if available and non-empty)
  let fields = [];
  let fieldsSource = 'none';
  
  try {
    const selectedOption = tablesSelect.selectedOptions[0];
    if (selectedOption && selectedOption.dataset.fields) {
      const cachedFields = JSON.parse(selectedOption.dataset.fields);
      if (cachedFields && cachedFields.length > 0) {
        fields = cachedFields;
        fieldsSource = 'dropdown';
        console.log('Using fields from dropdown data:', fields);
      }
    }
  } catch (e) {
    console.warn('Error parsing fields from select option:', e);
  }
  
  // If no fields from dropdown, fetch from API
  if (!fields || fields.length === 0) {
    try {
      console.log('Fetching fresh table details for table ID:', id);
      const td = await api('tableDetails', { id });
      console.log('Raw API response:', td);
      if (td && td.fields) {
        fields = td.fields;
        fieldsSource = 'api';
        console.log('Table details fetched from API:', fields);
      } else {
        console.warn('API returned no field data:', td);
        // Show debug info in the UI
        if (td && td.debug) {
          fieldsList.innerHTML = `<div class="field-row"><div class="field-name" style="color: var(--cp-muted);">Debug: Table ID ${td.debug.requested_id}, Field count: ${td.debug.field_count}, Has fields: ${td.debug.has_fields}</div></div>`;
        }
      }
    } catch (e) {
      console.error('Error fetching table details from API:', e);
      // Show error in the UI
      fieldsList.innerHTML = `<div class="field-row"><div class="field-name" style="color: var(--cp-red);">Error: ${e.message}</div></div>`;
    }
  }
  
  console.log(`Final fields (${fieldsSource}):`, fields);
  
  // Always render fields, even if empty
  renderFields(fields);

  // Reset and load table rows page 1
  paging = { 
    offset: 0,
    limit: 25,
    total: null,
    totalPages: null,
    currentPage: 1,
    recordCount: 0
  };
  
  // Always attempt to load table rows
  await loadTableRows(0, true);
}

function updatePagerUI(pagingData){
  const pageNum = pagingData.currentPage || paging.currentPage;
  const totalPages = pagingData.totalPages || paging.totalPages || '—';
  const totalRecs = pagingData.total !== undefined ? pagingData.total : (paging.total !== undefined ? paging.total : null);
  const recordCount = pagingData.recordCount || 0;
  
  // Update display with total records
  let displayText = `Page ${pageNum}`;
  if (totalPages !== '—') {
    displayText += ` of ${totalPages}`;
  }
  displayText += ` • ${recordCount} records on this page`;
  if (totalRecs !== null && totalRecs !== '—') {
    displayText += ` • ${totalRecs} total records`;
  }
  
  statsText.textContent = displayText;

  // Update button states
  const hasPrev = pagingData.hasPrev !== undefined ? pagingData.hasPrev : false;
  const hasNext = pagingData.hasNext !== undefined ? pagingData.hasNext : false;
  
  qs('#prevBtn').disabled = !hasPrev;
  qs('#firstBtn').disabled = !hasPrev;
  qs('#nextBtn').disabled = !hasNext;
  qs('#lastBtn').disabled = !hasNext || totalPages === '—';
  
  // Update paging state
  paging.currentPage = pageNum;
  paging.total = pagingData.total !== undefined ? pagingData.total : paging.total;
  paging.totalPages = pagingData.totalPages || paging.totalPages;
  paging.offset = pagingData.offset !== undefined ? pagingData.offset : paging.offset;
  paging.recordCount = recordCount;
}

async function loadTableRows(offset=0, reset=false){
  try {
    const data = await api('tableRows', { id: currentTableId, limit: 25, offset });
    
    if (!data.supported) {
      // Hide the data card entirely when table rows are not supported
      dataCard.style.display = 'none';
      rowsHint.style.display = 'block';
      rowsHint.textContent = data.message || 'HubDB table rows API not available or table not found.';
      rowsHint.className = 'notice notice-warning';
      return;
    }

    // Show the data card and hide any previous messages
    dataCard.style.display = 'block';
    rowsHint.style.display = 'none';
    rowsHint.className = 'hint'; // Reset to default styling
    
    // Show loading state
    if (currentColumns.length > 0) {
      tbodyRows.innerHTML = `<tr><td colspan="${currentColumns.length}" style="text-align: center; padding: 20px; color: var(--cp-muted);">Loading table data...</td></tr>`;
    } else {
      tbodyRows.innerHTML = '<tr><td>Loading…</td></tr>';
    }
    
    // Handle empty results
    if (!data.rows || data.rows.length === 0) {
      renderTable(data.columns || ['id'], []);
      // Show empty state
      tbodyRows.innerHTML = `<tr><td colspan="${data.columns?.length || 1}" style="text-align: center; padding: 20px; color: var(--cp-muted); font-style: italic;">No data found in this table</td></tr>`;
      updatePagerUI({ currentPage: 1, totalPages: 1, total: 0, recordCount: 0, hasNext: false, hasPrev: false, offset: 0 });
      return;
    }
    
    renderTable(data.columns, data.rows);
    
    // Update pagination state
    updatePagerUI(data.paging);
    
  } catch (e) {
    console.error('Error loading table rows:', e);
    // Hide the data card and show error message prominently
    dataCard.style.display = 'none';
    rowsHint.style.display = 'block';
    rowsHint.textContent = 'Error loading table data: ' + e.message;
    rowsHint.className = 'notice notice-error';
  }
}

// Pager events
qs('#nextBtn').addEventListener('click', async () => {
  const nextOffset = paging.offset + paging.limit;
  await loadTableRows(nextOffset);
});

qs('#prevBtn').addEventListener('click', async () => {
  const prevOffset = Math.max(0, paging.offset - paging.limit);
  await loadTableRows(prevOffset);
});

qs('#firstBtn').addEventListener('click', async () => {
  await loadTableRows(0, true);
});

qs('#lastBtn').addEventListener('click', async () => {
  if (paging.totalPages && paging.totalPages !== '—') {
    const lastOffset = (paging.totalPages - 1) * paging.limit;
    await loadTableRows(lastOffset);
  }
});

tablesSelect.addEventListener('change', onTableChange);
loadTables().catch(e=>{ tablesHint.textContent = 'Failed to load tables: ' + e.message; });
</script>
</body>
</html>