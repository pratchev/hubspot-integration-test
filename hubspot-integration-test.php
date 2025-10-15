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
            // v3 field schema lives under 'fieldGroups' (not 'formFieldGroups')
            if (isset($f['fieldGroups']) && is_array($f['fieldGroups'])) {
                foreach ($f['fieldGroups'] as $g) {
                    if (!empty($g['fields'])) {
                        foreach ($g['fields'] as $fld) {
                            $fields[] = [
                                'name' => $fld['name'] ?? '',
                                'label' => $fld['label'] ?? $fld['placeholder'] ?? ($fld['name'] ?? ''),
                                'type'  => $fld['fieldType'] ?? ($fld['type'] ?? ''),
                            ];
                        }
                    }
                }
            }
            // Also check for legacy 'formFieldGroups' structure
            elseif (isset($f['formFieldGroups']) && is_array($f['formFieldGroups'])) {
                foreach ($f['formFieldGroups'] as $g) {
                    if (!empty($g['fields'])) {
                        foreach ($g['fields'] as $fld) {
                            $fields[] = [
                                'name' => $fld['name'] ?? '',
                                'label' => $fld['label'] ?? $fld['placeholder'] ?? ($fld['name'] ?? ''),
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
                            'label' => $fld['label'] ?? $fld['placeholder'] ?? ($fld['name'] ?? ''),
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
    // Log the request for debugging
    error_log('Getting form details for form ID: ' . $formId);
    
    // v3
    $r = hs_request('GET', HUBSPOT_BASE . '/marketing/v3/forms/' . urlencode($formId));
    error_log('Form details v3 response: ' . json_encode($r));
    
    if ($r['ok'] && isset($r['data'])) {
        $fields = [];
        $formData = $r['data'];
        
        // Try different possible field structures - v3 uses 'fieldGroups'
        if (!empty($formData['fieldGroups'])) {
            foreach ($formData['fieldGroups'] as $g) {
                foreach (($g['fields'] ?? []) as $fld) {
                    $fields[] = [
                        'name' => $fld['name'] ?? '',
                        'label' => $fld['label'] ?? $fld['placeholder'] ?? ($fld['name'] ?? ''),
                        'type'  => $fld['fieldType'] ?? ($fld['type'] ?? ''),
                    ];
                }
            }
        }
        // Also check for legacy 'formFieldGroups' structure
        elseif (!empty($formData['formFieldGroups'])) {
            foreach ($formData['formFieldGroups'] as $g) {
                foreach (($g['fields'] ?? []) as $fld) {
                    $fields[] = [
                        'name' => $fld['name'] ?? '',
                        'label' => $fld['label'] ?? $fld['placeholder'] ?? ($fld['name'] ?? ''),
                        'type'  => $fld['fieldType'] ?? ($fld['type'] ?? ''),
                    ];
                }
            }
        } elseif (!empty($formData['fields'])) {
            // Some forms might have fields directly at the root level
            foreach ($formData['fields'] as $fld) {
                $fields[] = [
                    'name' => $fld['name'] ?? '',
                    'label' => $fld['label'] ?? $fld['placeholder'] ?? ($fld['name'] ?? ''),
                    'type'  => $fld['fieldType'] ?? ($fld['type'] ?? ''),
                ];
            }
        } elseif (!empty($formData['configuration']['createNewContactForNewEmail'])) {
            // Try to extract fields from configuration
            if (!empty($formData['configuration']['postSubmitActions'])) {
                foreach ($formData['configuration']['postSubmitActions'] as $action) {
                    if (!empty($action['fields'])) {
                        foreach ($action['fields'] as $fld) {
                            $fields[] = [
                                'name' => $fld['name'] ?? '',
                                'label' => $fld['label'] ?? $fld['placeholder'] ?? ($fld['name'] ?? ''),
                                'type'  => $fld['fieldType'] ?? ($fld['type'] ?? ''),
                            ];
                        }
                    }
                }
            }
        }
        
        error_log('Extracted fields from v3: ' . json_encode($fields));
        return ['id' => $formData['id'] ?? $formId, 'name' => $formData['name'] ?? '', 'fields' => $fields];
    }
    // v2 fallback
    error_log('Trying v2 form details API for form: ' . $formId);
    $r2 = hs_request('GET', HUBSPOT_BASE . '/forms/v2/forms/' . urlencode($formId));
    error_log('Form details v2 response: ' . json_encode($r2));
    
    if ($r2['ok'] && isset($r2['data'])) {
        $fields = [];
        $formData = $r2['data'];
        
        // Try different field structures for v2
        if (!empty($formData['formFieldGroups'])) {
            foreach ($formData['formFieldGroups'] as $g) {
                foreach (($g['fields'] ?? []) as $fld) {
                    $fields[] = [
                        'name' => $fld['name'] ?? '',
                        'label' => $fld['label'] ?? $fld['placeholder'] ?? ($fld['name'] ?? ''),
                        'type'  => $fld['fieldType'] ?? ($fld['type'] ?? ''),
                    ];
                }
            }
        } elseif (!empty($formData['fields'])) {
            // Some v2 forms might have fields directly
            foreach ($formData['fields'] as $fld) {
                $fields[] = [
                    'name' => $fld['name'] ?? '',
                    'label' => $fld['label'] ?? $fld['placeholder'] ?? ($fld['name'] ?? ''),
                    'type'  => $fld['fieldType'] ?? ($fld['type'] ?? ''),
                ];
            }
        }
        
        error_log('Extracted fields from v2: ' . json_encode($fields));
        return ['id' => $formId, 'name' => $formData['name'] ?? '', 'fields' => $fields];
    }
    
    error_log('No form details found for form ID: ' . $formId);
    return ['id' => $formId, 'name' => '', 'fields' => []];
}

/**
 * Get submissions for a form (25/page) using:
 * GET /form-integrations/v1/submissions/forms/{form_guid}?limit=25&after=<cursor>
 * Returns rows + paging info. Sorted by submittedAt desc if API supports; otherwise sort client-side.
 */
function get_form_submissions(string $formId, int $limit = 25, ?string $after = null): array {
    $query = ['limit' => $limit];
    if ($after) $query['after'] = $after;

    $url = HUBSPOT_BASE . '/form-integrations/v1/submissions/forms/' . urlencode($formId);
    $r   = hs_request('GET', $url, $query);

    if (!$r['ok']) {
        return [
            'supported' => false,
            'message'   => 'Form submissions API not available in this account or token scope. You can still validate forms & fields.',
            'rows'      => [],
            'columns'   => ['conversationId', 'submittedAt', 'pageUrl'],
            'paging'    => [
                'next' => null, 
                'prev' => null, 
                'hasNext' => false,
                'hasPrev' => false,
                'currentPage' => 1,
                'total' => 0,
                'totalPages' => 1
            ],
            'raw'       => $r
        ];
    }

    $data = $r['data'] ?? [];
    $results = $data['results'] ?? $data['submissions'] ?? [];
    $nextCursor = $data['paging']['next']['after'] ?? null;
    $total = $data['total'] ?? $data['paging']['total'] ?? null;
    
    // Debug: Log the total value
    error_log('API returned total: ' . ($total !== null ? $total : 'null'));
    error_log('Full API response structure: ' . json_encode($data, JSON_PRETTY_PRINT));

    // Normalize submissions to rows: each row = property => value, plus conversationId, submittedAt, pageUrl
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

        // New required fields
        $vals['conversationId'] = $sub['conversationId'] ?? '';
        $vals['submittedAt'] = $sub['submittedAt'] ?? '';
        $vals['pageUrl'] = $sub['pageUrl'] ?? '';
        $vals['_rawSubmittedAt'] = $sub['submittedAt'] ?? null; // Keep raw for sorting

        $rows[] = $vals;
        $allKeys = array_unique(array_merge($allKeys, array_keys($vals)));
    }

    // Ensure conversationId, submittedAt, and pageUrl are first; keep others after, but exclude internal fields
    $columns = array_values(array_unique(array_merge(
        ['conversationId', 'submittedAt', 'pageUrl'], 
        array_diff($allKeys, ['conversationId', 'submittedAt', 'pageUrl', '_rawSubmittedAt'])
    )));

    // Sort rows by submittedAt desc (client-side, just in case)
    usort($rows, function($a, $b) {
        $dateA = $a['_rawSubmittedAt'] ?? $a['submittedAt'] ?? '';
        $dateB = $b['_rawSubmittedAt'] ?? $b['submittedAt'] ?? '';
        return strcmp($dateB, $dateA); // Descending order
    });

    // Remove internal fields from output
    foreach ($rows as &$row) {
        unset($row['_rawSubmittedAt']);
    }

    // Calculate pagination info
    $currentPage = 1;
    if ($after) {
        // This is a rough estimate since we don't have perfect page tracking
        $currentPage = 2; // At minimum page 2 if we have an after cursor
    }
    
    $totalPages = $total ? max(1, ceil($total / $limit)) : null;
    $hasNext = !empty($nextCursor);
    $hasPrev = !empty($after);

    return [
        'supported' => true,
        'rows' => $rows,
        'columns' => $columns,
        'paging' => [
            'next' => $nextCursor,
            'prev' => $after,
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

/**
 * Get contacts from form submissions using CRM Search API
 * POST /crm/v3/objects/contacts/search
 * Filter: hs_calculated_form_submissions BETWEEN <formGuid>::1111111111111 and <formGuid>::9999999999999
 *         OR hs_calculated_form_submissions CONTAINS_TOKEN <formGuid>
 */
function get_contacts_from_form_submissions(string $formId, int $limit = 25, int $offset = 0): array {
    // Check if token is set
    if (HS_TOKEN === 'YOUR_HUBSPOT_TOKEN_HERE' || empty(HS_TOKEN)) {
        error_log('HubSpot token not configured for contacts search');
        return [
            'supported' => false,
            'message' => 'HubSpot token not configured. Please set HUBSPOT_TOKEN in .env file or edit the PHP file directly.',
            'rows' => [],
            'columns' => ['id', 'email', 'firstname', 'lastname', 'createdAt', 'franchise_id', 'hs_analytics_first_url'],
            'paging' => [
                'offset' => $offset,
                'limit' => $limit,
                'hasNext' => false,
                'hasPrev' => false,
                'currentPage' => 1,
                'total' => 0,
                'totalPages' => 1
            ]
        ];
    }

    $url = HUBSPOT_BASE . '/crm/v3/objects/contacts/search';
    
    $searchBody = [
        'filterGroups' => [
            [
                'filters' => [
                    [
                        'propertyName' => 'hs_calculated_form_submissions',
                        'operator' => 'BETWEEN',
                        'value' => $formId . '::1111111111111',
                        'highValue' => $formId . '::9999999999999'
                    ]
                ]
            ],
            [
                'filters' => [
                    [
                        'propertyName' => 'hs_calculated_form_submissions',
                        'operator' => 'CONTAINS_TOKEN',
                        'value' => $formId
                    ]
                ]
            ]
        ],
        'properties' => [
            'id', 'email', 'firstname', 'lastname', 'createdate', 'updatedAt',
            'phone', 'company', 'jobtitle', 'lifecyclestage', 'hs_calculated_form_submissions',
            'franchise_id', 'hs_analytics_first_url'
        ],
        'sorts' => [
            [
                'propertyName' => 'createdate',
                'direction' => 'DESCENDING'
            ]
        ],
        'limit' => $limit,
        'after' => $offset
    ];

    $r = hs_request('POST', $url, [], $searchBody);

    if (!$r['ok']) {
        error_log('Contacts search API failed: ' . json_encode($r));
        return [
            'supported' => false,
            'message' => 'Contacts search API failed. This might be due to insufficient token permissions or the contact property hs_calculated_form_submissions not being available.',
            'rows' => [],
            'columns' => ['id', 'email', 'firstname', 'lastname', 'createdAt', 'franchise_id', 'hs_analytics_first_url'],
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
    $nextOffset = isset($data['paging']['next']['after']) ? $data['paging']['next']['after'] : null;
    
    // Normalize contacts to rows
    $rows = [];
    $allKeys = ['id', 'email', 'firstname', 'lastname', 'createdate', 'franchise_id', 'hs_analytics_first_url']; // Standard columns first
    
    foreach ($results as $contact) {
        $vals = [];
        $properties = $contact['properties'] ?? [];
        
        // Add standard properties
        $vals['id'] = $contact['id'] ?? '';
        $vals['email'] = $properties['email'] ?? '';
        $vals['firstname'] = $properties['firstname'] ?? '';
        $vals['lastname'] = $properties['lastname'] ?? '';
        $vals['createdate'] = $properties['createdate'] ?? '';
        $vals['franchise_id'] = $properties['franchise_id'] ?? '';
        $vals['hs_analytics_first_url'] = $properties['hs_analytics_first_url'] ?? '';
        
        // Add additional properties
        foreach ($properties as $key => $value) {
            if (!in_array($key, ['id', 'email', 'firstname', 'lastname', 'createdate', 'franchise_id', 'hs_analytics_first_url'])) {
                $vals[$key] = is_array($value) ? json_encode($value) : $value;
                if (!in_array($key, $allKeys)) {
                    $allKeys[] = $key;
                }
            }
        }
        
        $rows[] = $vals;
    }

    // Calculate pagination
    $currentPage = floor($offset / $limit) + 1;
    $totalPages = $total > 0 ? ceil($total / $limit) : 1;
    $hasNext = !empty($nextOffset) || (($offset + $limit) < $total);
    $hasPrev = $offset > 0;

    return [
        'supported' => true,
        'rows' => $rows,
        'columns' => $allKeys,
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
            $result = get_form_details($id);
            // Add debug info to the response
            $result['debug'] = [
                'requested_id' => $id,
                'field_count' => count($result['fields'] ?? []),
                'has_fields' => !empty($result['fields'])
            ];
            echo json_encode($result);
            break;
        case 'submissions':
            $id = $_GET['id'] ?? '';
            $after = $_GET['after'] ?? null;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;
            echo json_encode(get_form_submissions($id, $limit, $after));
            break;
        case 'contacts':
            $id = $_GET['id'] ?? '';
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;
            echo json_encode(get_contacts_from_form_submissions($id, $limit, $offset));
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
  .props{
    max-height:240px; overflow:auto; border:1px solid var(--cp-border); border-radius:10px; padding:8px;
  }
  .prop-row{padding:8px 6px; border-bottom:1px dashed var(--cp-border)}
  .prop-row:last-child{border-bottom:0}
  .prop-name{font-weight:600; color:var(--cp-text)}
  .prop-type{color:var(--cp-muted); font-size:12px}
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
  .url-column{width:8%; min-width:65px; word-wrap:break-word; overflow-wrap:break-word;}
  .conversation-column{width:4%; min-width:35px;}
  .submitted-column{width:5%; min-width:40px;}}}
  .table-container{overflow-x:auto; max-width:100%;}
  .contacts-table-container{overflow-x:auto; max-width:100%; border:1px solid var(--cp-border); border-radius:10px;}
  .contacts-table{width:auto; border-collapse:separate; border-spacing:0; font-size:14px; min-width:1200px;}
  .contacts-table thead{background:var(--cp-navy); color:#fff; position:sticky; top:0;}
  .contacts-table th, .contacts-table td{padding:12px 16px; border-bottom:1px solid var(--cp-border); text-align: left; white-space:nowrap; min-width:120px;}
  .contacts-table th{font-weight: 600; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;}
  .contacts-table tbody tr:nth-child(even){background:#fafafa;}
  .contacts-table tbody tr:hover{background:#f0f4f8;}
  .contacts-table td{vertical-align: top;}
  .contacts-table th.email-col, .contacts-table td.email-col{min-width:200px; max-width:250px;}
  .contacts-table th.url-col, .contacts-table td.url-col{min-width:180px; max-width:300px; word-break:break-all;}
  .contacts-table th.id-col, .contacts-table td.id-col{min-width:100px; max-width:120px;}
  .contacts-table th.name-col, .contacts-table td.name-col{min-width:140px;}
  .contacts-table th.date-col, .contacts-table td.date-col{min-width:160px;}
  .contacts-table th.franchise-col, .contacts-table td.franchise-col{min-width:120px;}
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
    .url-column{min-width:50px}
    .conversation-column{min-width:30px}
    .submitted-column{min-width:35px}
  }
  @media (max-width:600px){
    .url-column{min-width:40px; font-size:10px}
    .conversation-column, .submitted-column{min-width:25px; font-size:10px}
    th, td{padding:6px 4px; font-size:11px}
    th{font-size:10px; line-height:1.2}
    .container{width:98%}
    .stats{font-size:12px}
  }
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

  <!-- Form submissions notice/error message -->
  <div class="hint" id="subsHint" style="display:none;"></div>

  <div class="card" id="dataCard" style="display:none">
    <div class="grid-title">Form Data</div>
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

  <!-- Contacts from form submissions notice/error message -->
  <div class="hint" id="contactsHint" style="display:none;"></div>

  <div class="card" id="contactsCard" style="display:none">
    <div class="grid-title">Contacts from Form Submissions</div>
    <div class="contacts-table-container">
      <div style="overflow:auto; max-height:70vh">
        <table id="contactsTable" class="contacts-table">
          <thead><tr id="contactsTheadRow"></tr></thead>
          <tbody id="contactsTbodyRows"></tbody>
        </table>
      </div>
    </div>
    <div class="toolbar">
      <div class="inline">
        <button class="btn" id="contactsFirstBtn">⏮ First</button>
        <button class="btn" id="contactsPrevBtn">◀ Prev</button>
        <button class="btn" id="contactsNextBtn">Next ▶</button>
        <button class="btn" id="contactsLastBtn">⏭ Last</button>
      </div>
      <div class="stats" id="contactsStatsText">Page 1 • 0 records on this page</div>
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
const contactsCard = qs('#contactsCard');
const contactsTheadRow = qs('#contactsTheadRow');
const contactsTbodyRows = qs('#contactsTbodyRows');
const contactsStatsText = qs('#contactsStatsText');
const contactsHint = qs('#contactsHint');

let currentFormId = null;
let currentColumns = [];
let paging = { 
  next: null, 
  prev: null, 
  total: null, 
  totalPages: null,
  currentPage: 1,
  cursorStack: [], // Stack of cursors for navigation
  recordCount: 0
};

let contactsPaging = {
  offset: 0,
  limit: 25,
  total: null,
  totalPages: null,
  currentPage: 1,
  recordCount: 0
};

let currentContactsColumns = [];

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
  const validFields = (fields || []).filter(f => f && (f.name || f.label));
  const max = validFields.length;
  propCount.textContent = max;
  
  if (max === 0) {
    const noFieldsMsg = document.createElement('div');
    noFieldsMsg.className = 'prop-row';
    noFieldsMsg.innerHTML = `<div class="prop-name" style="color: var(--cp-muted); font-style: italic;">No form fields found</div><div class="prop-type" style="color: var(--cp-muted); font-size: 12px;">This might indicate that the HubSpot token is not configured or the form has no fields.</div>`;
    propsList.appendChild(noFieldsMsg);
  } else {
    validFields.forEach((f, i) => {
      const row = document.createElement('div');
      row.className = 'prop-row';
      const label = f.label || f.name || 'Unnamed field';
      const name = f.name || '';
      const type = f.type || '';
      row.innerHTML = `<div class="prop-name">${label}</div><div class="prop-type">${name}${type ? ' • '+type : ''}</div>`;
      propsList.appendChild(row);
    });
  }
  propsCard.style.display = 'block';
}

function renderTable(columns, rows){
  currentColumns = columns;
  theadRow.innerHTML = '';
  columns.forEach(c=>{
    const th = document.createElement('th');
    // Capitalize and format column headers
    let displayName = c;
    if (c === 'conversationId') displayName = 'Conversation ID';
    else if (c === 'submittedAt') displayName = 'Submitted At';
    else if (c === 'pageUrl') displayName = 'Page URL';
    else displayName = c.charAt(0).toUpperCase() + c.slice(1);
    
    // Add CSS classes for column sizing
    if (c === 'pageUrl') th.className = 'url-column';
    else if (c === 'conversationId') th.className = 'conversation-column';
    else if (c === 'submittedAt') th.className = 'submitted-column';
    
    th.textContent = displayName;
    theadRow.appendChild(th);
  });
  
  tbodyRows.innerHTML = '';
  rows.forEach(r=>{
    const tr = document.createElement('tr');
    columns.forEach(c=>{
      const td = document.createElement('td');
      let value = r[c] ?? '';
      
      // Format dates for better readability
      if (c === 'submittedAt' && value) {
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
      
      // Add CSS classes for column sizing
      if (c === 'pageUrl') td.className = 'url-column';
      else if (c === 'conversationId') td.className = 'conversation-column';
      else if (c === 'submittedAt') td.className = 'submitted-column';
      
      // Add special styling for date columns
      if (c === 'submittedAt') {
        td.style.fontSize = '13px';
        td.style.color = 'var(--cp-muted)';
      }
      // Add special styling for URL columns
      if (c === 'pageUrl' && value) {
        td.style.fontSize = '12px';
        td.style.wordBreak = 'break-all';
        td.style.lineHeight = '1.4';
      }
      tr.appendChild(td);
    });
    tbodyRows.appendChild(tr);
  });
  dataCard.style.display = 'block';
}

function renderContactsTable(columns, rows){
  currentContactsColumns = columns;
  contactsTheadRow.innerHTML = '';
  columns.forEach(c=>{
    const th = document.createElement('th');
    // Capitalize and format column headers
    let displayName = c;
    if (c === 'id') displayName = 'Contact ID';
    else if (c === 'firstname') displayName = 'First Name';
    else if (c === 'lastname') displayName = 'Last Name';
    else if (c === 'createdate') displayName = 'Created Date';
    else if (c === 'updatedAt') displayName = 'Last Modified';
    else if (c === 'lifecyclestage') displayName = 'Lifecycle Stage';
    else if (c === 'jobtitle') displayName = 'Job Title';
    else if (c === 'hs_calculated_form_submissions') displayName = 'Form Submissions';
    else if (c === 'franchise_id') displayName = 'Franchise ID';
    else if (c === 'hs_analytics_first_url') displayName = 'First URL';
    else displayName = c.charAt(0).toUpperCase() + c.slice(1);
    
    // Add CSS classes for column sizing
    if (c === 'email') th.className = 'email-col';
    else if (c === 'hs_analytics_first_url') th.className = 'url-col';
    else if (c === 'id') th.className = 'id-col';
    else if (c === 'firstname' || c === 'lastname') th.className = 'name-col';
    else if (c === 'createdate' || c === 'updatedAt') th.className = 'date-col';
    else if (c === 'franchise_id') th.className = 'franchise-col';
    
    th.textContent = displayName;
    contactsTheadRow.appendChild(th);
  });
  
  contactsTbodyRows.innerHTML = '';
  rows.forEach(r=>{
    const tr = document.createElement('tr');
    columns.forEach(c=>{
      const td = document.createElement('td');
      let value = r[c] ?? '';
      
      // Format dates for better readability
      if ((c === 'createdate' || c === 'updatedAt') && value) {
        try {
          // HubSpot timestamps are in milliseconds
          const timestamp = parseInt(value);
          if (!isNaN(timestamp) && timestamp > 0) {
            const date = new Date(timestamp);
            if (date.getFullYear() > 1970) { // Sanity check
              value = date.toLocaleString();
            }
          }
        } catch (e) {
          // Keep original value if date parsing fails
        }
      }
      
      // Add CSS classes for column sizing
      if (c === 'email') td.className = 'email-col';
      else if (c === 'hs_analytics_first_url') td.className = 'url-col';
      else if (c === 'id') td.className = 'id-col';
      else if (c === 'firstname' || c === 'lastname') td.className = 'name-col';
      else if (c === 'createdate' || c === 'updatedAt') td.className = 'date-col';
      else if (c === 'franchise_id') td.className = 'franchise-col';
      
      // Format email as link
      if (c === 'email' && value) {
        const link = document.createElement('a');
        link.href = 'mailto:' + value;
        link.textContent = value;
        link.style.color = 'var(--cp-accent)';
        td.appendChild(link);
      } else {
        td.textContent = value;
      }
      
      // Add special styling for date columns
      if (c === 'createdate' || c === 'updatedAt') {
        td.style.fontSize = '13px';
        td.style.color = 'var(--cp-muted)';
      }
      
      tr.appendChild(td);
    });
    contactsTbodyRows.appendChild(tr);
  });
  contactsCard.style.display = 'block';
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
        debugMsg += 'HubSpot token not configured. Please set HUBSPOT_TOKEN in .env file or edit the PHP file directly.';
      } else {
        debugMsg += `Token configured (${data.debug.token_length} chars). Check token permissions for forms access.`;
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
    contactsCard.style.display = 'none';
    subsHint.style.display = 'none';
    contactsHint.style.display = 'none';
    formIdPill.textContent = '—';
    return;
  }
  
  currentFormId = id;
  formIdPill.textContent = id;

  // Try to get fields from the dropdown data first (if available and non-empty)
  let fields = [];
  let fieldsSource = 'none';
  
  try {
    const selectedOption = formsSelect.selectedOptions[0];
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
  
  // If no fields from dropdown, or very few fields, fetch from API
  if (!fields || fields.length === 0) {
    try {
      console.log('Fetching fresh form details for form ID:', id);
      const fd = await api('formDetails', { id });
      console.log('Raw API response:', fd);
      if (fd && fd.fields) {
        fields = fd.fields;
        fieldsSource = 'api';
        console.log('Form details fetched from API:', fields);
      } else {
        console.warn('API returned no field data:', fd);
        // Show debug info in the UI
        if (fd && fd.debug) {
          propsList.innerHTML = `<div class="prop-row"><div class="prop-name" style="color: var(--cp-muted);">Debug: Form ID ${fd.debug.requested_id}, Field count: ${fd.debug.field_count}, Has fields: ${fd.debug.has_fields}</div></div>`;
        }
      }
    } catch (e) {
      console.error('Error fetching form details from API:', e);
      // Show error in the UI
      propsList.innerHTML = `<div class="prop-row"><div class="prop-name" style="color: var(--cp-red);">Error: ${e.message}</div></div>`;
    }
  }
  
  console.log(`Final fields (${fieldsSource}):`, fields);
  
  // Always render properties, even if empty
  renderProps(fields);

  // Reset and load submissions page 1
  paging = { 
    next: null, 
    prev: null, 
    total: null, 
    totalPages: null,
    currentPage: 1,
    cursorStack: [],
    recordCount: 0
  };
  
  // Reset contacts paging
  contactsPaging = {
    offset: 0,
    limit: 25,
    total: null,
    totalPages: null,
    currentPage: 1,
    recordCount: 0
  };
  
  // Always attempt to load submissions - let loadSubmissions handle the "not supported" case
  await loadSubmissions(null, true);
  
  // Load contacts from form submissions
  await loadContacts(0, true);
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
  const hasPrev = pagingData.hasPrev !== undefined ? pagingData.hasPrev : (paging.cursorStack.length > 0);
  const hasNext = pagingData.hasNext !== undefined ? pagingData.hasNext : !!paging.next;
  
  qs('#prevBtn').disabled = !hasPrev;
  qs('#firstBtn').disabled = !hasPrev;
  qs('#nextBtn').disabled = !hasNext;
  qs('#lastBtn').disabled = !hasNext || totalPages === '—';
  
  // Update paging state
  paging.currentPage = pageNum;
  paging.total = pagingData.total !== undefined ? pagingData.total : paging.total;
  paging.totalPages = pagingData.totalPages || paging.totalPages;
  paging.next = pagingData.next;
  paging.recordCount = recordCount;
}

function updateContactsPagerUI(pagingData){
  const pageNum = pagingData.currentPage || contactsPaging.currentPage;
  const totalPages = pagingData.totalPages || contactsPaging.totalPages || '—';
  const totalRecs = pagingData.total !== undefined ? pagingData.total : (contactsPaging.total !== undefined ? contactsPaging.total : null);
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
  
  contactsStatsText.textContent = displayText;

  // Update button states
  const hasPrev = pagingData.hasPrev !== undefined ? pagingData.hasPrev : false;
  const hasNext = pagingData.hasNext !== undefined ? pagingData.hasNext : false;
  
  qs('#contactsPrevBtn').disabled = !hasPrev;
  qs('#contactsFirstBtn').disabled = !hasPrev;
  qs('#contactsNextBtn').disabled = !hasNext;
  qs('#contactsLastBtn').disabled = !hasNext || totalPages === '—';
  
  // Update contacts paging state
  contactsPaging.currentPage = pageNum;
  contactsPaging.total = pagingData.total !== undefined ? pagingData.total : contactsPaging.total;
  contactsPaging.totalPages = pagingData.totalPages || contactsPaging.totalPages;
  contactsPaging.offset = pagingData.offset !== undefined ? pagingData.offset : contactsPaging.offset;
  contactsPaging.recordCount = recordCount;
}

async function loadSubmissions(after=null, reset=false){
  try {
    const data = await api('submissions', { id: currentFormId, limit: 25, after });
    
    if (!data.supported) {
      // Hide the data card entirely when submissions are not supported
      dataCard.style.display = 'none';
      subsHint.style.display = 'block';
      subsHint.textContent = data.message || 'Form submissions API not available in this account or token scope. You can still validate forms & fields.';
      subsHint.className = 'notice notice-warning';
      return;
    }

    // Show the data card and hide any previous messages
    dataCard.style.display = 'block';
    subsHint.style.display = 'none';
    subsHint.className = 'hint'; // Reset to default styling
    
    // Show loading state
    if (currentColumns.length > 0) {
      tbodyRows.innerHTML = `<tr><td colspan="${currentColumns.length}" style="text-align: center; padding: 20px; color: var(--cp-muted);">Loading submissions...</td></tr>`;
    } else {
      tbodyRows.innerHTML = '<tr><td>Loading…</td></tr>';
    }
    
    // Handle empty results
    if (!data.rows || data.rows.length === 0) {
      renderTable(data.columns || ['conversationId', 'submittedAt', 'pageUrl'], []);
      // Show empty state
      tbodyRows.innerHTML = `<tr><td colspan="${data.columns?.length || 3}" style="text-align: center; padding: 20px; color: var(--cp-muted); font-style: italic;">No form submissions found</td></tr>`;
      updatePagerUI({ currentPage: 1, totalPages: 1, total: 0, recordCount: 0, hasNext: false, hasPrev: false });
      return;
    }
    
    renderTable(data.columns, data.rows);
    
    // Reset cursor stack if this is a fresh load
    if (reset) {
      paging.cursorStack = [];
      paging.currentPage = 1;
    }
    
    // Update pagination state
    updatePagerUI(data.paging);
    
  } catch (e) {
    console.error('Error loading submissions:', e);
    // Hide the data card and show error message prominently
    dataCard.style.display = 'none';
    subsHint.style.display = 'block';
    subsHint.textContent = 'Error loading submissions: ' + e.message;
    subsHint.className = 'notice notice-error';
  }
}

async function loadContacts(offset=0, reset=false){
  try {
    const data = await api('contacts', { id: currentFormId, limit: 25, offset });
    
    if (!data.supported) {
      // Hide the contacts card entirely when contacts API is not supported
      contactsCard.style.display = 'none';
      contactsHint.style.display = 'block';
      contactsHint.textContent = data.message || 'Contacts search API not available in this account or token scope.';
      contactsHint.className = 'notice notice-warning';
      return;
    }

    // Show the contacts card and hide any previous messages
    contactsCard.style.display = 'block';
    contactsHint.style.display = 'none';
    contactsHint.className = 'hint'; // Reset to default styling
    
    // Show loading state
    if (currentContactsColumns.length > 0) {
      contactsTbodyRows.innerHTML = `<tr><td colspan="${currentContactsColumns.length}" style="text-align: center; padding: 20px; color: var(--cp-muted);">Loading contacts...</td></tr>`;
    } else {
      contactsTbodyRows.innerHTML = '<tr><td>Loading…</td></tr>';
    }
    
    // Handle empty results
    if (!data.rows || data.rows.length === 0) {
      renderContactsTable(data.columns || ['id', 'email', 'firstname', 'lastname', 'createdate', 'franchise_id', 'hs_analytics_first_url'], []);
      // Show empty state
      contactsTbodyRows.innerHTML = `<tr><td colspan="${data.columns?.length || 5}" style="text-align: center; padding: 20px; color: var(--cp-muted); font-style: italic;">No contacts found for this form</td></tr>`;
      updateContactsPagerUI({ currentPage: 1, totalPages: 1, total: 0, recordCount: 0, hasNext: false, hasPrev: false, offset: 0 });
      return;
    }
    
    renderContactsTable(data.columns, data.rows);
    
    // Update pagination state
    updateContactsPagerUI(data.paging);
    
  } catch (e) {
    console.error('Error loading contacts:', e);
    // Hide the contacts card and show error message prominently
    contactsCard.style.display = 'none';
    contactsHint.style.display = 'block';
    contactsHint.textContent = 'Error loading contacts: ' + e.message;
    contactsHint.className = 'notice notice-error';
  }
}

// Pager events
qs('#nextBtn').addEventListener('click', async () => {
  if (!paging.next) return;
  // Store current cursor for back navigation
  const currentCursor = paging.next;
  paging.cursorStack.push({ cursor: currentCursor, page: paging.currentPage });
  paging.currentPage++;
  await loadSubmissions(currentCursor);
});

qs('#prevBtn').addEventListener('click', async () => {
  if (paging.cursorStack.length === 0) return;
  // Go back to previous page
  const lastState = paging.cursorStack.pop();
  paging.currentPage = Math.max(1, paging.currentPage - 1);
  
  // Determine cursor for previous page
  const prevCursor = paging.cursorStack.length > 0 ? 
    paging.cursorStack[paging.cursorStack.length - 1].cursor : null;
  
  await loadSubmissions(prevCursor);
});

qs('#firstBtn').addEventListener('click', async () => {
  paging.cursorStack = [];
  paging.currentPage = 1;
  await loadSubmissions(null, true);
});

qs('#lastBtn').addEventListener('click', async () => {
  // For cursor-based pagination, we need to navigate through pages to reach the last one
  if (!paging.totalPages || paging.totalPages === '—') {
    // If we don't know total pages, keep going until we can't go further
    let attempts = 0;
    const maxAttempts = 50; // Safety limit
    
    while (paging.next && attempts < maxAttempts) {
      const currentCursor = paging.next;
      paging.cursorStack.push({ cursor: currentCursor, page: paging.currentPage });
      paging.currentPage++;
      await loadSubmissions(currentCursor);
      attempts++;
      
      // Add a small delay to prevent overwhelming the API
      await new Promise(resolve => setTimeout(resolve, 100));
      
      // Break if we've reached the end
      if (!paging.next) break;
    }
  } else {
    // If we know the total pages, navigate more efficiently
    let attempts = 0;
    const maxAttempts = Math.min(paging.totalPages - paging.currentPage, 50);
    
    while (paging.next && paging.currentPage < paging.totalPages && attempts < maxAttempts) {
      const currentCursor = paging.next;
      paging.cursorStack.push({ cursor: currentCursor, page: paging.currentPage });
      paging.currentPage++;
      await loadSubmissions(currentCursor);
      attempts++;
      
      // Add a small delay to prevent overwhelming the API
      await new Promise(resolve => setTimeout(resolve, 100));
    }
  }
});

// Contacts pager events
qs('#contactsNextBtn').addEventListener('click', async () => {
  const nextOffset = contactsPaging.offset + contactsPaging.limit;
  await loadContacts(nextOffset);
});

qs('#contactsPrevBtn').addEventListener('click', async () => {
  const prevOffset = Math.max(0, contactsPaging.offset - contactsPaging.limit);
  await loadContacts(prevOffset);
});

qs('#contactsFirstBtn').addEventListener('click', async () => {
  await loadContacts(0, true);
});

qs('#contactsLastBtn').addEventListener('click', async () => {
  if (contactsPaging.totalPages && contactsPaging.totalPages !== '—') {
    const lastOffset = (contactsPaging.totalPages - 1) * contactsPaging.limit;
    await loadContacts(lastOffset);
  }
});

formsSelect.addEventListener('change', onFormChange);
loadForms().catch(e=>{ formsHint.textContent = 'Failed to load forms: ' + e.message; });
</script>
</body>
</html>
