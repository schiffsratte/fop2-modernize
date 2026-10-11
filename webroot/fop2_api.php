<?php
cors();
define('ALLOW_ACCESS', true);
require_once("config.php");
$inidata     = parse_ini_file("/usr/local/fop2/fop2.cfg",true);
$signing_key = '';
$credentials = array();
$userdirectory = __DIR__ . '/uploads/';

$headers = apache_request_headers();

if(isset($inidata['general']['jwt_key'])) {
    $signing_key = $inidata['general']['jwt_key'];

    if (strlen($signing_key) < 32) {
        error_log("JWT signing key is too weak");
        http_response_code(500);
        echo json_encode(array('error' => 'Internal server error'));
        exit;
    }
} else {
    forbidden();
}

if(isset($headers['Authorization'])) {
    if(!validate_authentication($signing_key)) {
        forbidden();
    } else {
        if(!has_permission()) {
            forbidden();
        }
    }
} else {
    forbidden();
}

include 'fop2_upgrade_db.php';

$context = $credentials['context']=='GENERAL'?'':$credentials['context'];
$exten   = $credentials['extension'];

// --- Routing Logic ---
$method = $_SERVER['REQUEST_METHOD'];
$request = explode('/', trim($_SERVER['PATH_INFO'], '/'));
$resource = array_shift($request);
$secondSegment = array_shift($request);

// Check if the second segment is a known action or an ID (numeric/string)
if ($secondSegment !== null) {
    if (in_array($secondSegment, ['search','import','export'])) {
        // It's an action
        $action = $secondSegment;
        $id = null;
    } else {
        // It's probably an ID
        $action = null;
        $id = $secondSegment;
    }
} else {
    $action = null;
    $id = null;
}

header('Content-Type: application/json');

switch ("$method $resource") {
    case 'GET templates':
        if ($action === 'search') {

            $query = isset($_GET['query']) ? $db->escape_string($_GET['query']) : '';
            $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
            $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 20;
            $offset = ($page - 1) * $limit;

            // Check if owner is provided
            $ownerCondition = "";
            $ownerParam = "";
            if (isset($_GET['owner'])) {
                $ownerCondition = " AND owner='%s'";
                $ownerParam = $_GET['owner'];
            }

            $searchQuery = "SELECT id, name, content, variables, owner, created_at, updated_at
                           FROM campaign_templates
                           WHERE active=1 AND context='%s'
                           $ownerCondition
                           AND (
                               name LIKE '%%%s%%' OR
                               content LIKE '%%%s%%'
                           )
                           ORDER BY name ASC
                           LIMIT $limit OFFSET $offset";

            if (isset($_GET['owner'])) {
                $templatesRes = $db->consulta($searchQuery, $context, $ownerParam, $query, $query);
            } else {
                $templatesRes = $db->consulta($searchQuery, $context, $query, $query);
            }

            $templates = array();
            while ($row = $db->fetch_assoc($templatesRes)) {
                $template = array(
                    'id' => intval($row['id']),
                    'name' => $row['name'],
                    'content' => $row['content'],
                    'variables' => $row['variables'] ? json_decode($row['variables'], true) : [],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at']
                );

                if (empty($template['variables'])) {
                    $template['variables'] = extractVariables($row['content']);
                }

                $templates[] = $template;
            }

            // Get total matching templates count
            $countQuery = "SELECT COUNT(*)
                          FROM campaign_templates
                          WHERE active=1 AND context='%s'
                          $ownerCondition
                          AND (
                              name LIKE '%%%s%%' OR
                              content LIKE '%%%s%%'
                          )";
            if (isset($_GET['owner'])) {
                $countRes = $db->consulta($countQuery, $context, $ownerParam, $query, $query);
            } else {
                $countRes = $db->consulta($countQuery, $context, $query, $query);
            }
            $total = $db->fetch_row($countRes)[0];

            echo json_encode(array(
                'data' => $templates,
                'pagination' => array(
                    'total' => intval($total),
                    'page' => $page,
                    'limit' => $limit,
                    'total_pages' => ceil($total / $limit)
                ),
                'search' => array(
                    'query' => $query,
                    'results' => count($templates)
                )
            ));

        } else {
            $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
            $limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 50;
            $offset = ($page - 1) * $limit;
            $one_template = "";

            if ($id) {
                $id = intval($id);
                $one_template = " AND id=$id ";
            }

            // Check if owner is provided
            $ownerCondition = "";
            $ownerParam = "";
            if (isset($_GET['owner'])) {
                $ownerCondition = " AND owner='%s'";
                $ownerParam = $_GET['owner'];
            }

            // Get templates with pagination
            $templatesQuery = "SELECT id, name, content, variables, owner, created_at, updated_at
                              FROM campaign_templates
                              WHERE active=1 AND context='%s'
                              $ownerCondition
                              $one_template
                              ORDER BY name ASC
                              LIMIT $limit OFFSET $offset";

            if (isset($_GET['owner'])) {
                $templatesRes = $db->consulta($templatesQuery, $context, $ownerParam);
            } else {
                $templatesRes = $db->consulta($templatesQuery, $context);
            }

            $templates = array();
            while ($row = $db->fetch_assoc($templatesRes)) {
                $template = [
                    'id' => intval($row['id']),
                    'name' => $row['name'],
                    'content' => $row['content'],
                    'variables' => $row['variables'] ? json_decode($row['variables'], true) : [],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at']
                ];

                // Extract variables from content if not stored
                if (empty($template['variables'])) {
                    $template['variables'] = extractVariables($row['content']);
                }

                $templates[] = $template;
            }

            $result = array();
            $result['data'] = $templates;

            if($one_template == '') {
                // Get total count for pagination
                $countQuery = "SELECT COUNT(*)
                              FROM campaign_templates
                              WHERE active=1 AND context='%s' $ownerCondition";
                if (isset($_GET['owner'])) {
                    $countRes = $db->consulta($countQuery, $context, $ownerParam);
                } else {
                    $countRes = $db->consulta($countQuery, $context);
                }
                $total = $db->fetch_row($countRes)[0];

                $result['pagination'] = array(
                    'total' => intval($total),
                    'page' => $page,
                    'limit' => $limit,
                    'total_pages' => ceil($total / $limit)
                );
            }

            echo json_encode($result);
        }
        break;

    case 'POST templates':
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || !isset($input['name']) || !isset($input['content'])) {
            http_response_code(400);
            echo json_encode(array('error' => 'Name and content are required'));
            break;
        }

        $name = $db->escape_string(trim($input['name']));
        $content = $db->escape_string(trim($input['content']));
        $variables = extractVariables($content);
        $variablesJson = json_encode($variables);

        // Check if template name already exists
        $checkQuery = "SELECT id FROM campaign_templates WHERE name='%s' AND owner='%s' AND active=1 AND context='%s'";
        $checkRes = $db->consulta($checkQuery, $name, $exten, $context);

        if ($db->num_rows($checkRes) > 0) {
            http_response_code(409);
            echo json_encode(array('error' => 'Template with this name already exists'));
            break;
        }

        $insertQuery = "INSERT INTO campaign_templates (name, content, variables, owner, context)
                       VALUES ('%s', '%s', '%s', '%s', '%s')";

        $insertRes = $db->consulta($insertQuery, $name, $content, $variablesJson, $exten, $context);

        if ($insertRes) {
            $newId = $db->insert_id();

            // Return the created template
            $getQuery = "SELECT id, name, content, variables, created_at, updated_at
                        FROM campaign_templates WHERE id=%d";
            $getRes = $db->consulta($getQuery, $newId);
            $row = $db->fetch_assoc($getRes);

            $template = [
                'id' => intval($row['id']),
                'name' => $row['name'],
                'content' => $row['content'],
                'variables' => json_decode($row['variables'], true),
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ];

            http_response_code(201);
            echo json_encode($template);
        } else {
            http_response_code(500);
            echo json_encode(array('error' => 'Failed to create template'));
        }
        break;

    case 'PUT templates':
        if (!$id) {
            http_response_code(400);
            echo json_encode(array('error' => 'Template ID is required'));
            break;
        }

        $id = intval($id);
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || !isset($input['name']) || !isset($input['content'])) {
            http_response_code(400);
            echo json_encode(array('error' => 'Name and content are required'));
            break;
        }

        $name = $db->escape_string(trim($input['name']));
        $content = $db->escape_string(trim($input['content']));
        $variables = extractVariables($content);
        $variablesJson = json_encode($variables);

        // Check if template exists and belongs to user
        $checkQuery = "SELECT id FROM campaign_templates WHERE id=%d AND owner='%s' AND active=1 AND context='%s'";
        $checkRes = $db->consulta($checkQuery, $id, $exten, $context);

        if ($db->num_rows($checkRes) == 0) {
            http_response_code(404);
            echo json_encode(array('error' => 'Template not found'));
            break;
        }

        $updateQuery = "UPDATE campaign_templates
                       SET name='%s', content='%s', variables='%s', updated_at=CURRENT_TIMESTAMP
                       WHERE id=%d AND owner='%s' AND context='%s'";

        $updateRes = $db->consulta($updateQuery, $name, $content, $variablesJson, $id, $exten, $context);

        if ($updateRes) {
            // Return the updated template
            $getQuery = "SELECT id, name, content, variables, created_at, updated_at
                        FROM campaign_templates WHERE id=%d";
            $getRes = $db->consulta($getQuery, $id);
            $row = $db->fetch_assoc($getRes);

            $template = [
                'id' => intval($row['id']),
                'name' => $row['name'],
                'content' => $row['content'],
                'variables' => json_decode($row['variables'], true),
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ];

            echo json_encode($template);
        } else {
            http_response_code(500);
            echo json_encode(array('error' => 'Failed to update template'));
        }
        break;

    case 'DELETE templates':
        if (!$id) {
            http_response_code(400);
            echo json_encode(array('error' => 'Template ID is required'));
            break;
        }

        $id = intval($id);

        // Check if template exists and belongs to user
        $checkQuery = "SELECT id FROM campaign_templates WHERE id=%d AND owner='%s' AND active=1 AND context='%s'";
        $checkRes = $db->consulta($checkQuery, $id, $exten, $context);

        if ($db->num_rows($checkRes) == 0) {
            http_response_code(404);
            echo json_encode(array('error' => 'Template not found'));
            break;
        }

        // Soft delete - set active to 0
        $deleteQuery = "UPDATE campaign_templates SET active=0, updated_at=CURRENT_TIMESTAMP
                       WHERE id=%d AND owner='%s' AND context='%s'";

        $deleteRes = $db->consulta($deleteQuery, $id, $exten, $context);

        if ($deleteRes) {
            echo json_encode(array('status' => 'deleted', 'id' => $id));
        } else {
            http_response_code(500);
            echo json_encode(array('error' => 'Failed to delete template'));
        }
        break;

    case 'GET tags':
        $tagQuery = "SELECT id,tag,color FROM visual_phonebook_tags WHERE context = '%s' ORDER BY tag";
        $tagRes = $db->consulta($tagQuery, $context);
        $result = array();
        while ($row = $db->fetch_assoc($tagRes)) {
            $row['color']=strtolower($row['color']);
            $result[] = $row;
        }
        echo json_encode(array(
            'data' => array_values($result),
        ));
        break;

    case 'POST tags':
        // Leer el cuerpo JSON de forma segura
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true);
        if (!is_array($input)) {
            $input = array();
        }

        $tagName = isset($input['tag']) ? trim($input['tag']) : '';
        $color   = isset($input['color']) ? strtolower(trim($input['color'])) : '';
        $tagContext = $context;

        if ($tagName === '') {
            http_response_code(400);
            echo json_encode(array('error' => 'Tag is mandatory'));
            break;
        }

        // Validar color hex (#RGB o #RRGGBB); si no viene o es inválido, usar color por defecto
        if ($color === '' || !preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/', $color)) {
            $color = '#888888';
        }

        // Avoid duplicated tags within the same context
        $checkSql = "SELECT id FROM visual_phonebook_tags WHERE tag='%s' AND context='%s'";
        $existing = $db->consulta($checkSql, $tagName, $tagContext);
        if ($existing && $db->num_rows($existing) > 0) {
            http_response_code(409);
            echo json_encode(array('error' => 'Tag already exists'));
            break;
        }

        $insertSql = "INSERT INTO visual_phonebook_tags (tag,color,context) VALUES ('%s','%s','%s')";
        $db->consulta($insertSql, $tagName, $color, $tagContext);

        $idRes = $db->consulta("SELECT LAST_INSERT_ID() AS id");
        $rowId = $db->fetch_assoc($idRes);
        $newId = isset($rowId['id']) ? (int)$rowId['id'] : 0;

        $sel = $db->consulta("SELECT id,tag,color FROM visual_phonebook_tags WHERE id=%d AND context='%s'", $newId, $tagContext);
        $created = $db->fetch_assoc($sel);
        if (!$created) {
            $created = array('id' => $newId, 'tag' => $tagName, 'color' => $color);
        } else {
            $created['color'] = strtolower($created['color']);
        }

        http_response_code(201);
        echo json_encode(array('data' => $created));
        break;

    case 'PUT tags':
        if (!$id) {
            http_response_code(400);
            echo json_encode(array('error' => 'Tag ID is required'));
            break;
        }

        $tagId = intval($id);

        $tagRowRes = $db->consulta("SELECT id, tag FROM visual_phonebook_tags WHERE id=%d AND context='%s'", $tagId, $context);
        if (!$tagRowRes || $db->num_rows($tagRowRes) === 0) {
            http_response_code(404);
            echo json_encode(array('error' => 'tag not found'));
            break;
        }
        $existingTagRow = $db->fetch_assoc($tagRowRes);

        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true);
        if (!is_array($input)) {
            $input = array();
        }

        $fields = array();
        if (isset($input['tag'])) {
            $tag = trim($input['tag']);
            if ($tag === '') {
                http_response_code(400);
                echo json_encode(array('error' => 'tag could not be empty'));
                break;
            }
            if (strcasecmp($tag, $existingTagRow['tag']) !== 0) {
                $duplicateCheck = $db->consulta("SELECT id FROM visual_phonebook_tags WHERE tag='%s' AND context='%s' AND id<>%d", $tag, $context, $tagId);
                if ($duplicateCheck && $db->num_rows($duplicateCheck) > 0) {
                    http_response_code(409);
                    echo json_encode(array('error' => 'Another tag with this name already exists'));
                    break;
                }
            }
            $fields['tag'] = $tag;
        }
        if (isset($input['color'])) {
            $color = trim($input['color']);
            if ($color === '' || !preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/', $color)) {
                http_response_code(400);
                echo json_encode(array('error' => 'invalid color (use #RGB or #RRGGBB)'));
                break;
            }
            $fields['color'] = $color;
        }

        if (empty($fields)) {
            http_response_code(400);
            echo json_encode(array('error' => 'nothing to update'));
            break;
        }

        // Armar SET dinámico manteniendo placeholders
        $sets = array();
        $args = array();
        foreach ($fields as $k => $v) {
            $sets[] = $k . "='%s'";
            $args[] = $v;
        }
        $args[] = $tagId;

        $updateSql = "UPDATE visual_phonebook_tags SET " . implode(',', $sets) . " WHERE id=%d AND context='%s'";

        // Llamada compatible con PHP 5.4 (sin "...")
        call_user_func_array(array($db, 'consulta'), array_merge(array($updateSql), $args, array($tagId, $context)));

        // Devolver actualizado
        $sel = $db->consulta("SELECT id,tag,color FROM visual_phonebook_tags WHERE id=%d AND context='%s'", $tagId, $context);
        $updated = $db->fetch_assoc($sel);
        if (!$updated) {
            http_response_code(404);
            echo json_encode(array('error' => 'grupo no encontrado'));
            break;
        }

        $updated['color'] = strtolower($updated['color']);

        echo json_encode(array('data' => $updated));
        break;

    case 'DELETE tags':
        if (!$id) {
            http_response_code(400);
            echo json_encode(array('error' => 'Tag ID is required'));
            break;
        }

        $tagId = intval($id);

        // Verificar existencia y pertenencia al contexto actual
        $chk = $db->consulta("SELECT id FROM visual_phonebook_tags WHERE id=%d AND context='%s'", $tagId, $context);
        $exists = $db->fetch_assoc($chk);
        if (!$exists) {
            http_response_code(404);
            echo json_encode(array('error' => 'tag not found'));
            break;
        }

        $db->consulta("DELETE FROM visual_phonebook_tags WHERE id=%d AND context='%s'", $tagId, $context);

        echo json_encode(array('data' => array('deleted' => true, 'id' => $tagId)));
        break;

    case 'GET participants':
        // get group participants, used by twilio_conversations in order to prevent creation of duplicated conversations
        // (a duplicated conversation is one with the exact same participants)
        $groupQuery = "SELECT tag,GROUP_CONCAT(number) AS participants FROM visual_phonebook_tags A LEFT JOIN visual_phonebook_tags_contacts B ON A.id=B.tag_id LEFT JOIN visual_phonebook_phones C ON B.contact_id=C.contact_id AND type='sms' WHERE context='%s' GROUP BY tag";
        $groupRes = $db->consulta($groupQuery, $context);
        $result = array();
        while ($row = $db->fetch_assoc($groupRes)) {
            $result[] = $row;
        }
        echo json_encode(array(
            'data' => array_values($result),
        ));
        break;

    case 'POST upload':
        if (!isset($_SERVER['CONTENT_TYPE']) || stripos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') === false) {
            http_response_code(400);
            echo json_encode(array('error' => 'Multipart form data required'));
            break;
        }

        $fieldName = null;
        if (isset($_FILES['file'])) {
            $fieldName = 'file';
        } elseif (isset($_FILES['picture'])) {
            $fieldName = 'picture';
        }

        if ($fieldName === null) {
            http_response_code(400);
            echo json_encode(array('error' => 'No file uploaded'));
            break;
        }

        $file = $_FILES[$fieldName];
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(array('error' => 'Failed to upload image'));
            break;
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            http_response_code(400);
            echo json_encode(array('error' => 'Invalid file upload'));
            break;
        }

        $maxSize = 5 * 1024 * 1024; // 5MB
        if ($file['size'] > $maxSize) {
            http_response_code(413);
            echo json_encode(array('error' => 'Image exceeds 5MB limit'));
            break;
        }

        $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
        if ($finfo) {
            $mimeType = finfo_file($finfo, $file['tmp_name']);
        } elseif (function_exists('mime_content_type')) {
            $mimeType = mime_content_type($file['tmp_name']);
        } else {
            $mimeType = $file['type'];
        }
        if ($finfo) {
            finfo_close($finfo);
        }

        $allowedMime = array(
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp'
        );

        if (!isset($allowedMime[$mimeType])) {
            http_response_code(400);
            echo json_encode(array('error' => 'Unsupported image type'));
            break;
        }

        try {
            $randomPart = bin2hex(random_bytes(8));
        } catch (Exception $e) {
            $randomPart = uniqid();
        }

        $extension = $allowedMime[$mimeType];
        $filename = sprintf('contact_%s.%s', $randomPart, $extension);

        $uploadsDir = __DIR__ . '/uploads';
        if (!is_dir($uploadsDir)) {
            if (!mkdir($uploadsDir, 0755, true) && !is_dir($uploadsDir)) {
                http_response_code(500);
                echo json_encode(array('error' => 'Failed to prepare upload directory'));
                break;
            }
        }

        $targetPath = $uploadsDir . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            http_response_code(500);
            echo json_encode(array('error' => 'Failed to store image '.$targetPath));
            break;
        }

        $relativePath = 'uploads/' . $filename;
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';

        // Get the script's directory relative to document root
        $scriptPath = dirname($_SERVER['SCRIPT_FILENAME']);
        $docRoot = $_SERVER['DOCUMENT_ROOT'];

        // Calculate the base path from document root to script directory
        $basePath = str_replace($docRoot, '', $scriptPath);
        $basePath = trim($basePath, '/');

        // Build the public URL
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';

        if ($basePath) {
            $publicUrl = $scheme . '://' . $host . '/' . $basePath . '/' . $relativePath;
        } else {
            $publicUrl = $scheme . '://' . $host . '/' . $relativePath;
        }

        http_response_code(201);
        echo json_encode(array(
            'filename' => $filename,
            'path'     => $relativePath,
            'url'      => $publicUrl
        ));
        break;

    case 'GET notes':
        $contactId = $id ? intval($id) : (isset($_GET['contact_id']) ? intval($_GET['contact_id']) : 0);
        $page   = isset($_GET['page'])   ? max(1, intval($_GET['page']))       : 1;
        $limit  = isset($_GET['limit'])  ? max(1, intval($_GET['limit']))      : 5;
        $offset = ($page - 1) * $limit;

        if ($contactId <= 0) {
            http_response_code(400);
            echo json_encode(array('error' => 'Contact ID is required'));
            break;
        }

        $contact = fetch_contact_if_accessible($db, $contactId, $context, $exten);
        if (!$contact) {
            http_response_code(404);
            echo json_encode(array('error' => 'Contact not found'));
            break;
        }

        $countQuery = "SELECT count(*) FROM fop2_notes WHERE contact_id = %d";
        $countRes = $db->consulta($countQuery, $contactId);
        $total = $db->fetch_row($countRes)[0];

        $pagination = array(
                    'total' => intval($total),
                    'page' => $page,
                    'limit' => $limit,
                    'total_pages' => ceil($total / $limit)
        );

        $noteQuery = "SELECT id, contact_id, datetime, note, author FROM fop2_notes WHERE contact_id = %d ORDER BY datetime DESC LIMIT $limit OFFSET $offset";
        $noteRes = $db->consulta($noteQuery, $contactId);
        $notes = array();
        if ($noteRes) {
            while ($row = $db->fetch_assoc($noteRes)) {
                $notes[] = array(
                    'id' => intval($row['id']),
                    'contact_id' => intval($row['contact_id']),
                    'datetime' => $row['datetime'],
                    'note' => $row['note'],
                    'author' => $row['author']
                );
            }
        } else {
            http_response_code(500);
            echo json_encode(array('error' => 'Could not load contact notes'));
            break;
        }

        if(count($notes)>0) {
            echo json_encode(array('data' => $notes, 'pagination' => $pagination));
        } else {
            echo json_encode(array('data' => array(), 'pagination' => array()));
        }
        break;

    case 'POST notes':
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || !isset($input['contact_id']) || intval($input['contact_id']) <= 0 || !isset($input['note']) || trim($input['note']) === '') {
            http_response_code(400);
            echo json_encode(array('error' => 'Contact ID and note text are required'));
            break;
        }

        $contactId = intval($input['contact_id']);
        $contact = fetch_contact_if_accessible($db, $contactId, $context, $exten);
        if (!$contact) {
            http_response_code(404);
            echo json_encode(array('error' => 'Contact not found'));
            break;
        }

        $noteText = $db->escape_string(trim($input['note']));
        $author = isset($input['author']) && trim($input['author']) !== '' ? $db->escape_string(trim($input['author'])) : $credentials['extension'];

        $insertNoteQuery = "INSERT INTO fop2_notes (contact_id, datetime, note, author) VALUES (%d, NOW(), '%s', '%s')";
        $insertRes = $db->consulta($insertNoteQuery, $contactId, $noteText, $author);

        if (!$insertRes) {
            http_response_code(500);
            echo json_encode(array('error' => 'Failed to create note'));
            break;
        }

        $newNoteId = $db->insert_id();
        $fetchNoteQuery = "SELECT id, contact_id, datetime, note, author FROM fop2_notes WHERE id = %d";
        $noteRes = $db->consulta($fetchNoteQuery, $newNoteId);
        $noteData = $db->fetch_assoc($noteRes);

        http_response_code(201);
        echo json_encode(array(
            'id' => intval($noteData['id']),
            'contact_id' => intval($noteData['contact_id']),
            'datetime' => $noteData['datetime'],
            'note' => $noteData['note'],
            'author' => $noteData['author']
        ));
        break;

    case 'PUT notes':
        if (!$id) {
            http_response_code(400);
            echo json_encode(array('error' => 'Note ID is required'));
            break;
        }

        $noteId = intval($id);
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || !isset($input['note']) || trim($input['note']) === '') {
            http_response_code(400);
            echo json_encode(array('error' => 'Note text is required'));
            break;
        }

        $note = fetch_note_if_accessible($db, $noteId, $context, $exten);
        if (!$note) {
            http_response_code(404);
            echo json_encode(array('error' => 'Note not found'));
            break;
        }

        $noteText = $db->escape_string(trim($input['note']));
        $author = isset($input['author']) && trim($input['author']) !== '' ? $db->escape_string(trim($input['author'])) : $note['author'];

        $updateNoteQuery = "UPDATE fop2_notes SET note = '%s', author = '%s', datetime = NOW() WHERE id = %d";
        $updateRes = $db->consulta($updateNoteQuery, $noteText, $author, $noteId);

        if (!$updateRes) {
            http_response_code(500);
            echo json_encode(array('error' => 'Failed to update note'));
            break;
        }

        $updatedNote = fetch_note_if_accessible($db, $noteId, $context, $exten);
        echo json_encode(array(
            'id' => intval($updatedNote['id']),
            'contact_id' => intval($updatedNote['contact_id']),
            'datetime' => $updatedNote['datetime'],
            'note' => $updatedNote['note'],
            'author' => $updatedNote['author']
        ));
        break;

    case 'DELETE notes':
        if (!$id) {
            http_response_code(400);
            echo json_encode(array('error' => 'Note ID is required'));
            break;
        }

        $noteId = intval($id);
        $note = fetch_note_if_accessible($db, $noteId, $context, $exten);
        if (!$note) {
            http_response_code(404);
            echo json_encode(array('error' => 'Note not found'));
            break;
        }

        $deleteNoteQuery = "DELETE FROM fop2_notes WHERE id = %d";
        $deleteRes = $db->consulta($deleteNoteQuery, $noteId);

        if (!$deleteRes) {
            http_response_code(500);
            echo json_encode(array('error' => 'Failed to delete note'));
            break;
        }

        echo json_encode(array('status' => 'deleted', 'id' => $noteId));
        break;

    case 'GET contacts':
        if ($action === 'search') {
            $filter = isset($_GET['filter']) ? $db->escape_string($_GET['filter']) : '';
            $query  = isset($_GET['query'])  ? $db->escape_string($_GET['query'])  : '';
            $tag    = isset($_GET['tag'])  ? $db->escape_string($_GET['tag'])  : '';
            $page   = isset($_GET['page'])   ? max(1, intval($_GET['page']))       : 1;
            $limit  = isset($_GET['limit'])  ? max(1, intval($_GET['limit']))      : 20;
            $offset = ($page - 1) * $limit;

            $qfilter = '';
            if($filter!='') {
                if($filter=='sms') {
                    $qfilter = " AND (B.type='sms' OR B.type='whatsapp') ";
                }
            }

            $gfilter = '';
            if($tag!='') {
              $gfilter = "AND T.tag = '%s'";
            }

            // 1. First get matching contact IDs with pagination
            $searchQuery = "SELECT DISTINCT A.id
                           FROM visual_phonebook A
                           LEFT JOIN visual_phonebook_phones B ON A.id=B.contact_id
                           LEFT JOIN visual_phonebook_tags_contacts TC ON A.id=TC.contact_id
                           LEFT JOIN visual_phonebook_tags T ON T.id=TC.tag_id
                           WHERE A.context='%s'
                           $gfilter
                           AND (owner='%s' OR (owner<>'%s' AND private='no'))
                           AND (
                               A.firstname LIKE '%%%s%%' OR
                               A.lastname LIKE '%%%s%%' OR
                               A.company LIKE '%%%s%%' OR
                               B.number LIKE '%%%s%%'
                           ) $qfilter
                           ORDER BY CONCAT(COALESCE(A.firstname, ''), COALESCE(A.lastname, ''))
                           LIMIT $limit OFFSET $offset";

            if($gfilter=='') {
              $contactRes = $db->consulta($searchQuery, $context, $exten, $exten, $query, $query, $query, $query);
            } else {
              $contactRes = $db->consulta($searchQuery, $context, $tag, $exten, $exten, $query, $query, $query, $query);
            }
            $contactIds = array();
            while ($row = $db->fetch_assoc($contactRes)) {
                $contactIds[] = $row['id'];
            }

            if (empty($contactIds)) {
                echo json_encode(array('data' => array(), 'pagination' => array()));
                break;
            }

            // 2. Fetch complete contact data with numbers
            $idsStr = implode(',', $contactIds);
            $fullQuery = "SELECT
                         A.id, A.firstname, A.lastname, A.company, A.address, A.email,
                         A.picture, A.identification, A.position, A.private, T.tag,
                         P.type, P.number
                         FROM visual_phonebook A
                         LEFT JOIN visual_phonebook_phones P ON A.id=P.contact_id
                         LEFT JOIN visual_phonebook_tags_contacts TC ON A.id=TC.contact_id
                         LEFT JOIN visual_phonebook_tags T ON T.id=TC.tag_id
                         WHERE A.id IN ($idsStr)
                         ORDER BY A.firstname, A.lastname, P.type";

            $fullRes = $db->consulta($fullQuery);
            $contacts = array();
            while ($row = $db->fetch_assoc($fullRes)) {
                $id = $row['id'];
                if (!isset($contacts[$id])) {
                    $contacts[$id] = array(
                        'id' => $id,
                        'firstname' => $row['firstname'],
                        'lastname' => $row['lastname'],
                        'company' => $row['company'],
                        'address' => $row['address'],
                        'email' => $row['email'],
                        'picture' => resolve_contact_picture($row['picture']),
                        'identification' => $row['identification'],
                        'position' => $row['position'],
                        'private' => isset($row['private']) ? $row['private'] : 'no',
                        'tag' => null,
                        'tags' => array(),
                        'numbers' => array()
                    );
                }
                if (!empty($row['tag']) && !in_array($row['tag'], $contacts[$id]['tags'], true)) {
                    $contacts[$id]['tags'][] = $row['tag'];
                }
                if (!empty($row['number'])) {
                    $isDuplicate = false;
                    foreach ($contacts[$id]['numbers'] as $existingNumber) {
                        if ($existingNumber['type'] === $row['type'] && $existingNumber['number'] === $row['number']) {
                            $isDuplicate = true;
                            break;
                        }
                    }
                    if(!$isDuplicate) {
                        $num = preg_replace('/[^0-9]/', '', $row['number']);
                        $num = ($row['number'][0] === '+' ? '+' : '') . $num;
                        $contacts[$id]['numbers'][] = array(
                           'type' => $row['type'],
                           'number' => $num
                        );
                    }
                }
            }

            foreach ($contacts as &$contactRef) {
                if (!empty($contactRef['tags'])) {
                    $contactRef['tag'] = $contactRef['tags'][0];
                }
            }
            unset($contactRef);

            // 3. Get total matching contacts count
            $countQuery = "SELECT COUNT(DISTINCT A.id)
                          FROM visual_phonebook A
                          LEFT JOIN visual_phonebook_phones B ON A.id=B.contact_id
                          WHERE A.context='%s'
                          AND (owner='%s' OR (owner<>'%s' AND private='no'))
                          AND (
                              A.firstname LIKE '%%%s%%' OR
                              A.lastname LIKE '%%%s%%' OR
                              A.company LIKE '%%%s%%' OR
                              B.number LIKE '%%%s%%'
                          ) $qfilter";
            $countRes = $db->consulta($countQuery, $context, $exten, $exten, $query, $query, $query, $query);
            $total = $db->fetch_row($countRes)[0];

            // 4. Return structured response
            echo json_encode(array(
                'data' => array_values($contacts),
                'pagination' => array(
                    'total' => intval($total),
                    'page' => $page,
                    'limit' => $limit,
                    'total_pages' => ceil($total / $limit)
                ),
                'search' => array(
                    'query' => $query,
                    'results' => count($contacts)
                )
            ));

        } else if($action=='export') {
            $search    = isset($_GET['query'])  ? $db->escape_string($_GET['query'])  : '';
            $tag       = isset($_GET['tag'])  ? $db->escape_string($_GET['tag'])  : '';
            $extension = isset($_GET['extension'])  ? $db->escape_string($_GET['extension'])  : '';

            $gfilter = '';
            if($tag!='') {
              $gfilter = "AND C.tag = '%s'";
            }

            $mensaje=array();
            $search = preg_replace("/\"/","",$search);
            $search = preg_replace("/;/","",$search);
            if($search<>"") {
                $condition = " (CONCAT(firstname,' ',lastname) LIKE \"%%%s%%\" OR company LIKE \"%%%s%%\" OR number LIKE \"%%%s%%\" OR email LIKE \"%%%s%%\" OR position LIKE \"%%%s%%\" OR identification LIKE \"%%%s%%\" OR tag LIKE \"%%%s%%\")";
            } else {
                $condition="1=1";
            }

            $query = "SELECT A.id,firstname,lastname,company,address,email,type,number,picture,identification,position,tag
                        FROM visual_phonebook A
                        LEFT JOIN visual_phonebook_phones ON id=contact_id
                        LEFT JOIN visual_phonebook_tags_contacts B on A.id=B.contact_id
                        LEFT JOIN visual_phonebook_tags C ON C.id=B.tag_id
                        WHERE A.context='%s' AND (owner='%s' OR (owner<>'%s' AND private='no')) AND ($condition) $gfilter ORDER BY firstname,lastname,number";


            if($tag!='') {
                if($search!='') {
                    $res = $db->consulta($query,array($context,$extension,$extension,$search,$search,$search,$search,$search,$search,$search,$tag));
                } else {
                    $res = $db->consulta($query,array($context,$extension,$extension,$tag));
                }
            } else {
                $res = $db->consulta($query,array($context,$extension,$extension,$search,$search,$search,$search,$search,$search,$search));
            }
            $cuantos = $db->num_rows($res);

            if($cuantos==0) {
                http_response_code(400);
                echo json_encode(array('error' => 'No records to export'));
                exit;
            }
            @ob_end_clean();
            $filename = "contacts.vcf";
            header('Content-Type: text/x-vcard;charset=utf-8;');
            header("Content-Disposition: attachment; filename=$filename");
            $curid = 0;
            $cont = 0;
            $record = array();
            while ($row = $db->fetch_assoc($res)) {

               if($curid!=$row['id'] && $cont>0) {
                   $rec = "BEGIN:VCARD\n";
                   $rec.= "VERSION:3.0\n";
                   ksort($record);
                   foreach($record as $key=>$val) {
                       if($key=='CATEGORIES') {
                           $linea="$key:".implode(",",$val);
                       } else {
                           $linea="$key:$val";
                       }
                       if(strlen($linea)<75) {
                           $rec.="$linea\n";
                       } else {
                           $lineas = str_split($linea,75);
                           $i=0;
                           foreach($lineas as $linea) {
                               if($i==0) {
                                   $rec.="$linea\n";
                               } else {
                                   $rec.=" $linea\n";
                               }
                               $i++;
                           }
                       }
                   }
                   $rec.= "END:VCARD\n";
                   echo $rec;
                   if($cont+1<$cuantos) {
                       // clear record array if its not the last one
                       $record = array();
                   }
               }

               $record['FN']=$row['firstname']." ".$row['lastname'];
               $record['N']=$row['lastname'].";".$row['firstname'].";;;";
               if($row['address']!='')        { $record['ADR;TYPE=HOME']=';;'.$row['address'].";;;;".$row['address']; }
               if($row['number']!='')         { $record['TEL;TYPE='.strtoupper($row['type'])]=$row['number']; }
               if($row['picture']!='')        { $record['PHOTO']=$row['picture']; }
               if($row['company']!='')        { $record['item1.ORG']=$row['company']; }
               if($row['identification']!='') { $record['X-IDENTIFICATION']=$row['identification']; }
               if($row['position']!='')       { $record['ROLE']=$row['position']; }
               if($row['tag']!='')            { if(!isset($record['CATEGORIES'])) { $record['CATEGORIES']=array(); } $record['CATEGORIES'][$row['tag']]=$row['tag']; }

               $curid = $row['id'];
               $cont++;
            }
            $rec = "BEGIN:VCARD\n";
            $rec.= "VERSION:3.0\n";
            ksort($record);
            foreach($record as $key=>$val) {
                if($key=='CATEGORIES') {
                    $linea="$key:".implode(",",$val);
                } else {
                    $linea="$key:$val";
                }
                if(strlen($linea)<75) {
                    $rec.="$linea\n";
                } else {
                    $lineas = str_split($linea,75);
                    $i=0;
                    foreach($lineas as $linea) {
                        if($i==0) {
                            $rec.="$linea\n";
                        } else {
                            $rec.=" $linea\n";
                        }
                        $i++;
                    }
                }
            }
            $rec.= "END:VCARD\n";
            echo $rec;
            exit;

        } else {

            $filter = isset($_GET['filter']) ? $db->escape_string($_GET['filter']) : '';
            $page   = isset($_GET['page'])   ? max(1, intval($_GET['page'])) : 1;
            $limit  = isset($_GET['limit'])  ? max(1, intval($_GET['limit'])) : 20;
            $offset = ($page - 1) * $limit;

            $tagFilter = isset($_GET['tag']) ? $db->escape_string($_GET['tag']) : '';
            $joinClause = '';
            $whereClause = '';
            $one_contact = '';

            $qfilter = '';
            if($filter!='') {
                if($filter=='sms') {
                    $qfilter = " AND (B.type='sms' OR B.type='whatsapp') ";
                }
            }

            if (!empty($tagFilter)) {
                // Join with tags tables if filtering by tag
                $joinClause = "LEFT JOIN visual_phonebook_tags_contacts TC ON A.id=TC.contact_id
                               LEFT JOIN visual_phonebook_tags C_TAGS ON C_TAGS.id=TC.tag_id";
                $tagFilter = $db->escape_string($tagFilter);
                $whereClause = " AND C_TAGS.tag = '$tagFilter' ";
            }

            if (!empty($request)) {
                $id = intval($request[0]);
                $one_contact = " AND A.id=$id ";
            }

            // 1. First, get paginated contact IDs
            $contactQuery = "SELECT DISTINCT A.id
                            FROM visual_phonebook A
                            LEFT JOIN visual_phonebook_phones B ON A.id=B.contact_id
                            $joinClause
                            WHERE A.context='%s'
                            AND (owner='%s' OR (owner<>'%s' AND private='no'))
                            $one_contact
                            $whereClause
                            $qfilter
                            ORDER BY CONCAT(COALESCE(A.firstname, ''), COALESCE(A.lastname, ''))
                            LIMIT $limit OFFSET $offset";

            $contactRes = $db->consulta($contactQuery, $context, $exten, $exten);

            // 2. Fetch all phone numbers for these contacts in one query
            $contactIds = array();
            while ($row = $db->fetch_assoc($contactRes)) {
                $contactIds[] = $row['id'];
            }

            if (empty($contactIds)) {
                if($one_contact=='') {
                    echo json_encode(array('data' => array(), 'pagination' => array('total'=>0, 'page'=>1, 'limit'=>$limit, 'total_pages'=>0)));
                } else {
                    echo json_encode(array('data' => array()));
                }
                break;
            }

            $idsStr = implode(',', $contactIds);
            $fullQuery = "SELECT
                     A.id, firstname, lastname, company, address, email,
                         picture, identification, position, A.private, C.tag,
                         B.type, B.number
                         FROM visual_phonebook A
                         LEFT JOIN visual_phonebook_phones B ON A.id=B.contact_id
                         LEFT JOIN visual_phonebook_tags_contacts TC ON A.id=TC.contact_id
                         LEFT JOIN visual_phonebook_tags C ON C.id=TC.tag_id
                         WHERE A.id IN ($idsStr)
                         ORDER BY A.firstname, A.lastname, B.type";

            $fullRes = $db->consulta($fullQuery);

            // 3. Group results by contact
            $contacts = array();
            while ($row = $db->fetch_assoc($fullRes)) {
                $id = $row['id'];
                if (!isset($contacts[$id])) {
                    $contacts[$id] = array(
                        'id' => $id,
                        'firstname' => $row['firstname'],
                        'lastname' => $row['lastname'],
                        'company' => $row['company'],
                        'address' => $row['address'],
                        'email' => $row['email'],
                        'picture' => resolve_contact_picture($row['picture']),
                        'identification' => $row['identification'],
                        'position' => $row['position'],
                        'private' => isset($row['private']) ? $row['private'] : 'no',
                        'tag' => null,
                        'tags' => array(),
                        'numbers' => array()
                    );
                }
                if (!empty($row['tag']) && !in_array($row['tag'], $contacts[$id]['tags'], true)) {
                    $contacts[$id]['tags'][] = $row['tag'];
                }
                if (!empty($row['number'])) {
                    $isDuplicate = false;
                    foreach ($contacts[$id]['numbers'] as $existingNumber) {
                        if ($existingNumber['type'] === $row['type'] && $existingNumber['number'] === $row['number']) {
                            $isDuplicate = true;
                            break;
                        }
                    }
                    if(!$isDuplicate) {
                        $num = preg_replace('/[^0-9]/', '', $row['number']);
                        $num  = ($row['number'][0] === '+' ? '+' : '') . $num;
                        $contacts[$id]['numbers'][] = array(
                            'type' => $row['type'],
                            'number' => $num
                        );
                    }
                }
            }

            $result = array();
            foreach ($contacts as &$contactRef) {
                if (!empty($contactRef['tags'])) {
                    $contactRef['tag'] = $contactRef['tags'][0];
                }
            }
            unset($contactRef);

            $result['data']=array_values($contacts);
            if($one_contact=='') {

                // 4. Get total count for pagination
                $countQuery = "SELECT COUNT(DISTINCT A.id)
                               FROM visual_phonebook A
                               LEFT JOIN visual_phonebook_phones B ON A.id=B.contact_id
                               $joinClause
                               WHERE A.context='%s'
                               AND (owner='%s' OR (owner<>'%s' AND private='no'))
                               $whereClause $qfilter";

                $totalContacts = $db->consulta($countQuery, $context, $exten, $exten)->fetch_row()[0];

                $result['pagination']=array(
                        'total' => intval($totalContacts),
                        'page' => $page,
                        'limit' => $limit,
                        'total_pages' => ceil($totalContacts / $limit)
                );
            }
            // 5. Return structured response
            echo json_encode($result);
        }
        break;

    // Add contact (POST /contacts)
    case 'POST contacts':
        if($action=='import') {

            $arrFile = $_FILES['file'];
            $file    = $arrFile['tmp_name'];
            $extension = $_POST['extension'];
            $error   = array();

            if ($arrFile['size']>0 && !empty($file)) {
                if (is_uploaded_file($file)) {
                    if (copy ($file, $userdirectory."contactsimportCSV.csv")) {
                        $name_upload="contactsimportCSV.csv";
                    } else {
                        $error[]=array('kind'=>'warning','mesage'=>trans('Could not copy uploaded file'));
                    }
                } else {
                    $error[]=array('kind'=>'warning','message'=>trans('Could not upload file'));
                }
            } else {
                $error[]=array('kind'=>'warning','message'=>trans('Could not upload file'));
            }

            if($name_upload == "") {
                $error[]=array('kind'=>'warning','message'=>trans('Is file empty?'));
            }
            if(count($error)>0) {
                http_response_code(400); // Bad Request
                echo json_encode(array('success' => false, 'errors' => $error));
            } else {
                $result = process_vcard($userdirectory."contactsimportCSV.csv", $extension, $context);
                echo json_encode($result);
            }
            exit;

        } else {
            $input = json_decode(file_get_contents('php://input'), true);

            if (!$input || !isset($input['firstname']) || trim($input['firstname']) === '') {
                http_response_code(400);
                echo json_encode(array('error' => 'First name is required'));
                break;
            }

            $phoneEntries = extract_valid_phone_numbers(isset($input['numbers']) ? $input['numbers'] : array());
            if (count($phoneEntries) === 0) {
                http_response_code(400);
                echo json_encode(array('error' => 'At least one phone number is required'));
                break;
            }

            $db->start_transaction('visual_phonebook');

            try {
                $firstname      = $db->escape_string(trim($input['firstname']));
                $lastname       = isset($input['lastname']) ? $db->escape_string(trim($input['lastname'])) : '';
                $company        = isset($input['company']) ? $db->escape_string(trim($input['company'])) : '';
                $address        = isset($input['address']) ? $db->escape_string(trim($input['address'])) : '';
                $email          = isset($input['email']) ? $db->escape_string(trim($input['email'])) : '';
                $identification = isset($input['identification']) ? $db->escape_string(trim($input['identification'])) : '';
                $position       = isset($input['position']) ? $db->escape_string(trim($input['position'])) : '';
                $picture        = isset($input['picture']) ? $db->escape_string(trim($input['picture'])) : '';
                $private        = isset($input['private']) && $input['private'] === 'yes' ? 'yes' : 'no';
                $owner          = $exten;

                $insertContactQuery = "INSERT INTO visual_phonebook (firstname, lastname, company, address, email, picture, identification, position, context, owner, private)
                                       VALUES ('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')";
                $insertRes = $db->consulta($insertContactQuery, $firstname, $lastname, $company, $address, $email, $picture, $identification, $position, $context, $owner, $private);

                if (!$insertRes) {
                    throw new Exception("Failed to create contact record.");
                }

                $newContactId = $db->insert_id();

                // Insert phone numbers
                foreach ($phoneEntries as $phone) {
                    $phoneType = $db->escape_string($phone['type']);
                    $phoneNumber = $db->escape_string($phone['number']);
                    $insertPhoneQuery = "INSERT INTO visual_phonebook_phones (contact_id, `type`, number) VALUES (%d, '%s', '%s')";
                    $db->consulta($insertPhoneQuery, $newContactId, $phoneType, $phoneNumber);
                }

                // Handle tags
                if (isset($input['tags']) && is_array($input['tags'])) {
                    $tagIdsToAssign = array();
                    foreach ($input['tags'] as $tagRequest) {
                        $tagId = resolve_tag_identifier($db, $tagRequest, $context);
                        if ($tagId !== null) {
                            $tagIdsToAssign[] = $tagId;
                        }
                    }

                    $tagIdsToAssign = array_values(array_unique($tagIdsToAssign));
                    foreach ($tagIdsToAssign as $tagId) {
                        $insertTagContactQuery = "INSERT INTO visual_phonebook_tags_contacts (tag_id, contact_id) VALUES (%d, %d)";
                        $db->consulta($insertTagContactQuery, $tagId, $newContactId);
                    }
                }

                $db->commit();

                // Fetch and return the newly created contact data
                $getNewContactQuery = "SELECT
                                    A.id, firstname, lastname, company, address, email,
                                    picture, identification, position, private,
                                    (SELECT GROUP_CONCAT(T.tag) FROM visual_phonebook_tags_contacts TC JOIN visual_phonebook_tags T ON TC.tag_id = T.id WHERE TC.contact_id = A.id) as tags
                                 FROM visual_phonebook A
                                 WHERE A.id = %d AND A.context = '%s'";

                $getRes = $db->consulta($getNewContactQuery, $newContactId, $context);
                $newContactData = $db->fetch_assoc($getRes);
                $newContactData['picture'] = resolve_contact_picture(isset($newContactData['picture']) ? $newContactData['picture'] : '');

                $getPhonesQuery = "SELECT type, number FROM visual_phonebook_phones WHERE contact_id = %d";
                $getPhonesRes = $db->consulta($getPhonesQuery, $newContactId);
                $newContactData['numbers'] = array();
                while($row = $db->fetch_assoc($getPhonesRes)) {
                    $newContactData['numbers'][] = $row;
                }
                $newContactData['tags'] = isset($newContactData['tags']) && $newContactData['tags'] !== null && $newContactData['tags'] !== ''
                    ? array_values(array_filter(array_map('trim', explode(',', $newContactData['tags']))))
                    : array();
                $newContactData['tag'] = !empty($newContactData['tags']) ? $newContactData['tags'][0] : null;

                http_response_code(201);
                echo json_encode($newContactData);

            } catch (InvalidArgumentException $e) {
                $db->rollback();
                http_response_code(400);
                echo json_encode(array('error' => $e->getMessage()));
            } catch (Exception $e) {
                $db->rollback();
                http_response_code(500);
                echo json_encode(array('error' => 'Failed to create contact', 'message' => $e->getMessage()));
            }
        }
        break;

    // Delete contact (DELETE /contacts/:id)
    case 'DELETE contacts':

        $id = intval($id);

        $contactRow = fetch_contact_if_accessible($db, $id, $context, $exten);
        if (!$contactRow) {
            http_response_code(404);
            echo json_encode(array('error' => 'Contact not found'));
            break;
        }

        if ((string)$contactRow['owner'] !== (string)$exten && $contactRow['private']=='yes') {
            http_response_code(403);
            echo json_encode(array('error' => 'You cannot delete contacts owned by another user'));
            break;
        }

        // Attempt to delete the contact
        $deleteQuery = "DELETE FROM visual_phonebook WHERE id = %d AND context = '%s'";
        $delRes = $db->consulta($deleteQuery, $id, $context);

        if ($delRes) {
            echo json_encode(array('status' => 'deleted'));
        } else {
            http_response_code(500);
            echo json_encode(array('error' => 'Failed to delete contact'));
        }
        break;

    case 'PUT contacts':

        $id = intval($id);

        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || !isset($input['firstname']) || trim($input['firstname'])==='') {
            http_response_code(400);
            echo json_encode(array('error' => 'First name is required'));
            break;
        }

        $phoneEntries = extract_valid_phone_numbers(isset($input['numbers']) ? $input['numbers'] : array());
        if (count($phoneEntries) === 0) {
            http_response_code(400);
            echo json_encode(array('error' => 'At least one phone number is required'));
            break;
        }

        $contactRow = fetch_contact_if_accessible($db, $id, $context, $exten);
        if (!$contactRow) {
            http_response_code(404);
            echo json_encode(array('error' => 'Contact not found'));
            break;
        }

        if ((string)$contactRow['owner'] !== (string)$exten && $contactRow['private']=='yes') {
            http_response_code(403);
            echo json_encode(array('error' => 'You cannot modify contacts owned by another user'));
            break;
        }

        $existingContactRes = $db->consulta("SELECT picture FROM visual_phonebook WHERE id = %d AND context = '%s'", $id, $context);
        if (!$existingContactRes || $db->num_rows($existingContactRes) === 0) {
            http_response_code(404);
            echo json_encode(array('error' => 'Contact not found'));
            break;
        }
        $existingContact = $db->fetch_assoc($existingContactRes);

        $db->start_transaction('visual_phonebook');

        try {
            $firstname      = $db->escape_string(trim($input['firstname']));
            $lastname       = isset($input['lastname']) ? $db->escape_string(trim($input['lastname'])) : '';
            $company        = isset($input['company']) ? $db->escape_string(trim($input['company'])) : '';
            $address        = isset($input['address']) ? $db->escape_string(trim($input['address'])) : '';
            $email          = isset($input['email']) ? $db->escape_string(trim($input['email'])) : '';
            $identification = isset($input['identification']) ? $db->escape_string(trim($input['identification'])) : '';
            $position       = isset($input['position']) ? $db->escape_string(trim($input['position'])) : '';
            $pictureInput   = array_key_exists('picture', $input) ? trim((string)$input['picture']) : $existingContact['picture'];
            $picture        = $db->escape_string($pictureInput);
            $private        = isset($input['private']) && $input['private'] === 'yes' ? 'yes' : 'no';

            $updateContactQuery = "UPDATE visual_phonebook SET firstname = '%s', lastname = '%s', company = '%s', address = '%s', email = '%s', picture = '%s', identification = '%s', position = '%s', private = '%s' WHERE id = %d AND context = '%s'";
            $updateRes = $db->consulta($updateContactQuery, $firstname, $lastname, $company, $address, $email, $picture, $identification, $position, $private, $id, $context);

            if (!$updateRes) {
                throw new Exception("Failed to update contact record.");
            }

            // Delete existing phone numbers and tags
            $db->consulta("DELETE FROM visual_phonebook_phones WHERE contact_id = %d", $id);
            $db->consulta("DELETE FROM visual_phonebook_tags_contacts WHERE contact_id = %d", $id);

            // Insert phone numbers
            foreach ($phoneEntries as $phone) {
                $phoneType = $db->escape_string($phone['type']);
                $phoneNumber = $db->escape_string($phone['number']);
                $insertPhoneQuery = "INSERT INTO visual_phonebook_phones (contact_id, `type`, number) VALUES (%d, '%s', '%s')";
                $db->consulta($insertPhoneQuery, $id, $phoneType, $phoneNumber);
            }

            // Handle tags
            if (isset($input['tags']) && is_array($input['tags'])) {
                $tagIdsToAssign = array();
                foreach ($input['tags'] as $tagRequest) {
                    $tagId = resolve_tag_identifier($db, $tagRequest, $context);
                    if ($tagId !== null) {
                        $tagIdsToAssign[] = $tagId;
                    }
                }

                $tagIdsToAssign = array_values(array_unique($tagIdsToAssign));
                foreach ($tagIdsToAssign as $tagId) {
                    $insertTagContactQuery = "INSERT INTO visual_phonebook_tags_contacts (tag_id, contact_id) VALUES (%d, %d)";
                    $db->consulta($insertTagContactQuery, $tagId, $id);
                }
            }

            $db->commit();

            // Fetch and return the newly created contact data
            $getNewContactQuery = "SELECT
                                    A.id, firstname, lastname, company, address, email,
                                    picture, identification, position, private,
                                    (SELECT GROUP_CONCAT(T.tag) FROM visual_phonebook_tags_contacts TC JOIN visual_phonebook_tags T ON TC.tag_id = T.id WHERE TC.contact_id = A.id) as tags
                                 FROM visual_phonebook A
                                 WHERE A.id = %d AND A.context = '%s'";

            $getRes = $db->consulta($getNewContactQuery, $id, $context);
            $newContactData = $db->fetch_assoc($getRes);
            $newContactData['picture'] = resolve_contact_picture(isset($newContactData['picture']) ? $newContactData['picture'] : '');

            $getPhonesQuery = "SELECT type, number FROM visual_phonebook_phones WHERE contact_id = %d";
            $getPhonesRes = $db->consulta($getPhonesQuery, $id);
            $newContactData['numbers'] = array();
            while($row = $db->fetch_assoc($getPhonesRes)) {
                $newContactData['numbers'][] = $row;
            }
            $newContactData['tags'] = isset($newContactData['tags']) && $newContactData['tags'] !== null && $newContactData['tags'] !== ''
                ? array_values(array_filter(array_map('trim', explode(',', $newContactData['tags']))))
                : array();
            $newContactData['tag'] = !empty($newContactData['tags']) ? $newContactData['tags'][0] : null;

            http_response_code(200);
            echo json_encode($newContactData);

        } catch (InvalidArgumentException $e) {
            $db->rollback();
            http_response_code(400);
            echo json_encode(array('error' => $e->getMessage()));
        } catch (Exception $e) {
            $db->rollback();
            http_response_code(500);
            echo json_encode(array('error' => 'Failed to update contact', 'message' => $e->getMessage()));
        }
        break;

    default:
        http_response_code(404);
        echo json_encode(array('error' => 'Endpoint not found ('."$method $endpoint $action".')'));
}

// HELPER FUNCTIONS

/**
 * Processes a vCard file and imports contacts into the database.
 * Now returns a summary of the import operation.
 *
 * @param string $file The path to the vCard file.
 * @param string $user_id The ID of the user who owns the contacts.
 * @param string $context The tenant/context for the imported contacts.
 * @return array An array containing the import summary.
 */
function process_vcard($file, $user_id, $context) {
    global $db;

    $existing_tags = [];
    $count_color = 0;
    $hex_colors = ['#2e9fe2', '#bb0983', '#faf113', '#42c200', '#00079f', '#ba6800', '#900402', '#309981', '#6100b1', '#8de000', '#e001cd', '#70a402', '#2d8b00', '#d20a20', '#064cc2', '#a5053c'];

    // Initialize counters and error collection
    $summary = [
        'imported' => 0,
        'failed' => 0,
        'errors' => []
    ];

    $tagLookupSql = "SELECT tag, id FROM visual_phonebook_tags WHERE context='%s'";
    $res = $db->consulta($tagLookupSql, $context);
    while ($row = $db->fetch_row($res)) {
        $existing_tags[trim($row[0])] = (int)$row[1];
    }

    $fp = fopen($file, "r");
    if (!$fp) {
        $summary['errors'][] = "Could not open the uploaded file.";
        return $summary;
    }

    $lastfield = '';
    while (($line = fgets($fp)) !== false) {
        $line = chop($line);
        if ($line == 'BEGIN:VCARD') {
            $data = [];
            $phones = [];
        }
        if (substr($line, 0, 1) == " ") {
            if ($lastfield <> '') {
                $data[$lastfield] = $data[$lastfield] . substr($line, 1);
            }
        }
        if (substr($line, 0, 2) == "N:") {
            $milinea = preg_split("/;/", substr($line, 2));
            $data['lastname'] = $milinea[0] ?? '';
            $data['firstname'] = $milinea[1] ?? '';
            $lastfield = '';
        }
        if (substr($line, 0, 5) == "PHOTO") {
            $milinea = preg_split("/:/", $line, 2);
            $data['picture'] = $milinea[1] ?? '';
            $lastfield = 'picture';
        }
        if (substr($line, 0, 4) == "TEL;") {
            $milinea = preg_split("/:/", substr($line, 4));
            $milinea2 = strtolower(substr($milinea[0], 5));
            $partes = preg_split("/;/", $milinea2, 2);
            $type = $partes[0] ?? '';
            $number = $milinea[1] ?? '';
            $phones[] = ['type' => $type, 'number' => $number];
        }
        if (preg_match("/ADR;TYPE/", $line)) {
            $milinea = preg_split("/:/", $line);
            $data['address'] = $milinea[1] ?? '';
            $lastfield = 'address';
        }
        if (preg_match("/EMAIL;TYPE/", $line)) {
            $milinea = preg_split("/:/", $line);
            $data['email'] = $milinea[1] ?? '';
            $lastfield = 'email';
        }
        if (preg_match("/item\d.ORG:(.*)/", $line, $matches)) {
            $data['company'] = $matches[1] ?? '';
            $lastfield = 'company';
        }
        if (preg_match("/^X-IDENTIFICATION:(.*)/", $line, $matches)) {
            $data['identification'] = $matches[1] ?? '';
            $lastfield = 'identification';
        }
        if (preg_match("/^ROLE:(.*)/", $line, $matches)) {
            $data['position'] = $matches[1] ?? '';
            $lastfield = 'position';
        }
        if (preg_match("/^CATEGORIES:(.*)/", $line, $matches)) {
            $data['tags'] = preg_split("/,/", $matches[1]);
            $lastfield = 'tags';
        }

        if ($line == 'END:VCARD') {
            if (count($phones) > 0) {
                // Use a try/catch block to handle errors for individual contacts
                try {
                    // Set default values to avoid undefined index errors
                    $data['company'] = $data['company'] ?? '';
                    $data['email'] = $data['email'] ?? '';
                    $data['picture'] = $data['picture'] ?? '';
                    $data['identification'] = $data['identification'] ?? '';
                    $data['position'] = $data['position'] ?? '';
                    $data['tags'] = $data['tags'] ?? [];
                    $data['address'] = $data['address'] ?? '';

                    $partes = preg_split("/;/", $data['address']);
                    if (isset($partes[7])) {
                        $address = preg_replace("/\\\,/", ",", $partes[7]);
                    } else {
                        $address = preg_replace("/\\\,/", ",", $partes[2] ?? '');
                    }
                    $address = str_replace('\n', ',', $address);

                    // NOTE: Make sure $user_id is defined, e.g., from the session.
                    // It was incorrectly using $extension before.
                    $insertSql = "INSERT INTO visual_phonebook(firstname,lastname,company,address,email,picture,identification,position,context,owner,private) VALUES ('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s')";
                    $res = $db->consulta(
                        $insertSql,
                        $data['firstname'],
                        $data['lastname'],
                        $data['company'],
                        $address,
                        $data['email'],
                        $data['picture'],
                        $data['identification'],
                        $data['position'],
                        $context,
                        $user_id,
                        'no'
                    );
                    $lastid = $db->insert_id();

                    if ($lastid > 0) {
                        foreach ($phones as $phone) {
                            $phoneType = isset($phone['type']) ? trim($phone['type']) : '';
                            $phoneNumber = isset($phone['number']) ? trim($phone['number']) : '';
                            if ($phoneNumber === '') {
                                continue;
                            }
                            if ($phoneType === '') {
                                $phoneType = 'other';
                            }
                            $insertPhoneSql = "INSERT INTO visual_phonebook_phones (contact_id,type,number) VALUES ('%d','%s','%s')";
                            $db->consulta($insertPhoneSql, $lastid, $phoneType, $phoneNumber);
                        }
                        $linkedTags = array();
                        foreach ($data['tags'] as $tag) {
                            $tagName = trim($tag);
                            if ($tagName === '') {
                                continue;
                            }
                            if (!isset($existing_tags[$tagName])) {
                                $mycolor = $hex_colors[$count_color];
                                $insertTagSql = "INSERT INTO visual_phonebook_tags (tag,color,context) VALUES ('%s','%s','%s')";
                                $db->consulta($insertTagSql, $tagName, $mycolor, $context);
                                $tag_id = $db->insert_id();
                                $existing_tags[$tagName] = $tag_id;
                                $count_color++;
                                if ($count_color >= 15) {
                                    $count_color = 0;
                                }
                            } else {
                                $tag_id = $existing_tags[$tagName];
                            }
                            if (isset($linkedTags[$tag_id])) {
                                continue;
                            }
                            $linkedTags[$tag_id] = true;
                            $insertTagLinkSql = "INSERT INTO visual_phonebook_tags_contacts (contact_id,tag_id) VALUES ('%d','%d')";
                            $db->consulta($insertTagLinkSql, $lastid, $tag_id);
                        }
                        $summary['imported']++;
                    } else {
                        throw new Exception("Database failed to insert contact: {$data['firstname']} {$data['lastname']}");
                    }
                } catch (Exception $e) {
                    // If any part of the contact insertion fails, count it as a failure and log the error
                    $summary['failed']++;
                    $summary['errors'][] = "Failed to import contact '{$data['firstname']} {$data['lastname']}': " . $e->getMessage();
                }
            }
        }
    }
    fclose($fp);
    return $summary;
}

function old_process_vcard($file,$extension) {
    global $context;
    process_vcard($file, $extension, isset($context) ? $context : '');
}

function extractVariables($content) {
    $matches = array();
    preg_match_all('/{{(\w+)}}/', $content, $matches);
    return array_unique($matches[1]);
}

function resolve_contact_picture($picture) {
    if (!is_string($picture)) {
        return '';
    }

    $trimmed = trim($picture);
    if ($trimmed === '') {
        return '';
    }

    if (preg_match('/^(https?:)?\/\//i', $trimmed) || strpos($trimmed, 'data:') === 0) {
        return $trimmed;
    }

    $normalized = ltrim(str_replace('\\', '/', $trimmed), '/');
    if ($normalized === '' || strpos($normalized, '..') !== false) {
        return '';
    }

    $full_path = __DIR__ . '/uploads/' . $normalized;
    if (is_file($full_path)) {
        return $normalized;
    }

    return '';
}

function extract_valid_phone_numbers($numbers) {
    $result = array();
    if (!is_array($numbers)) {
        return $result;
    }

    foreach ($numbers as $phone) {
        if (!is_array($phone)) {
            continue;
        }
        $number = isset($phone['number']) ? trim((string)$phone['number']) : '';
        if ($number === '') {
            continue;
        }
        $type = isset($phone['type']) ? trim((string)$phone['type']) : 'other';
        if ($type === '') {
            $type = 'other';
        }

        $result[] = array(
            'type' => $type,
            'number' => $number
        );
    }

    if (count($result) <= 1) {
        return $result;
    }

    $unique = array();
    foreach ($result as $entry) {
        $key = strtolower($entry['type']) . ':' . $entry['number'];
        if (!isset($unique[$key])) {
            $unique[$key] = $entry;
        }
    }

    return array_values($unique);
}

function resolve_tag_identifier($db, $tagRequest, $context) {
    if (is_int($tagRequest)) {
        $tagId = $tagRequest;
        $tagExists = $db->consulta("SELECT id FROM visual_phonebook_tags WHERE id=%d AND context='%s'", $tagId, $context);
        if ($tagExists && $db->num_rows($tagExists) > 0) {
            return $tagId > 0 ? (int)$tagId : null;
        }
        throw new InvalidArgumentException('Invalid tag reference');
    }

    if (is_string($tagRequest)) {
        $trimmed = trim($tagRequest);
        if ($trimmed === '') {
            return null;
        }

        if (ctype_digit($trimmed)) {
            $numericId = (int)$trimmed;
            $tagExists = $db->consulta("SELECT id FROM visual_phonebook_tags WHERE id=%d AND context='%s'", $numericId, $context);
            if ($tagExists && $db->num_rows($tagExists) > 0) {
                return $numericId > 0 ? $numericId : null;
            }
            // Fall through and treat as literal tag name if not found
        }

        $lookup = $db->consulta("SELECT id FROM visual_phonebook_tags WHERE tag='%s' AND context='%s'", $trimmed, $context);
        if ($lookup && $db->num_rows($lookup) > 0) {
            $row = $db->fetch_assoc($lookup);
            $existingId = (int)$row['id'];
            return $existingId > 0 ? $existingId : null;
        }

        $db->consulta("INSERT INTO visual_phonebook_tags (tag, context, color) VALUES ('%s', '%s', '#FFFFFF')", $trimmed, $context);
        $newId = (int)$db->insert_id();
        if ($newId <= 0) {
            throw new InvalidArgumentException('Failed to create tag');
        }
        return $newId;
    }

    if (is_float($tagRequest)) {
        $numericId = (int)$tagRequest;
        $tagExists = $db->consulta("SELECT id FROM visual_phonebook_tags WHERE id=%d AND context='%s'", $numericId, $context);
        if ($tagExists && $db->num_rows($tagExists) > 0) {
            return $numericId > 0 ? $numericId : null;
        }
        throw new InvalidArgumentException('Invalid tag reference');
    }

    return null;
}

function fetch_contact_if_accessible($db, $contactId, $context, $exten) {
    $contactId = intval($contactId);
    if ($contactId <= 0) {
        return null;
    }

    $contactQuery = "SELECT id, owner, private FROM visual_phonebook WHERE id = %d AND context = '%s' AND (owner = '%s' OR (owner <> '%s' AND private = 'no'))";
    $contactRes = $db->consulta($contactQuery, $contactId, $context, $exten, $exten);
    if ($contactRes && $db->num_rows($contactRes) > 0) {
        return $db->fetch_assoc($contactRes);
    }

    return null;
}

function fetch_note_if_accessible($db, $noteId, $context, $exten) {
    $noteId = intval($noteId);
    if ($noteId <= 0) {
        return null;
    }

    $noteQuery = "SELECT N.id, N.contact_id, N.datetime, N.note, N.author
                  FROM fop2_notes N
                  JOIN visual_phonebook C ON C.id = N.contact_id
                  WHERE N.id = %d AND C.context = '%s' AND (C.owner = '%s' OR (C.owner <> '%s' AND C.private = 'no'))";
    $noteRes = $db->consulta($noteQuery, $noteId, $context, $exten, $exten);
    if ($noteRes && $db->num_rows($noteRes) > 0) {
        return $db->fetch_assoc($noteRes);
    }

    return null;
}

function forbidden() {
    http_response_code(403);
    header('Content-Type: application/json');
    header('WWW-Authenticate: Bearer realm="API", error="invalid_token", error_description="The access token is invalid or expired"');
    echo json_encode(array('error' => 'Forbidden: You do not have permission'));
    exit;
}

function verify_jwt_token($token, $signing_key) {
    global $credentials;
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false; // Invalid token format
    }
    list($header_encoded, $payload_encoded, $signature_encoded) = $parts;
    // Decode the header and payload
    $header = base64url_decode($header_encoded);
    $payload = base64url_decode($payload_encoded);
    // Verify the signature
    $signature0 = base64url_encode(hash_hmac('sha512', $header_encoded . '.' . $payload_encoded, $signing_key, false));
    $signature1 = base64_encode($signature0);
    $expected_signature = base64url_encode($signature0);
    if (my_hash_equals($signature_encoded, $signature0)) {
        //return json_decode($payload, true);
        $credentials = json_decode($payload,true);
        $expiration_time = $credentials['exp'];
        $current_time = time();
        if ($expiration_time <= $current_time) {
            return false; // Token has expired
        } else {
            return true;
        }
    } else {
        return false; // Invalid signature
    }
    return true;
}

function validate_authentication($signing_key) {
    $headers = apache_request_headers();

    if (!isset($headers['Authorization'])) {
        return false;
    }

    $authorization_header = $headers['Authorization'];

    // Case-insensitive check for Bearer token
    if (stripos($authorization_header, 'Bearer ') !== 0) {
        return false;
    }

    $token = substr($authorization_header, 7); // Remove 'Bearer ' prefix
    $token = trim($token);

    if (empty($token)) {
        return false;
    }

    return verify_jwt_token($token, $signing_key);
}

function base64url_decode($data) {
    $data = str_replace(['-', '_'], ['+', '/'], $data);
    $padding = strlen($data) % 4;
    if ($padding > 0) {
        $data .= str_repeat('=', 4 - $padding);
    }
    return base64_decode($data);
}

function base64url_encode($data) {
    return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
}

function my_hash_equals($known_string, $user_string) {
    // Timing attack safe comparison
    if (strlen($known_string) !== strlen($user_string)) {
        return false;
    }

    $result = 0;
    for ($i = 0; $i < strlen($known_string); $i++) {
        $result |= ord($known_string[$i]) ^ ord($user_string[$i]);
    }

    return $result === 0;
}

function has_permission() {
    global $credentials;
    if (!isset($credentials['permit']) || empty($credentials['permit'])) {
        return false;
    }

    $decodedPerms = base64_decode($credentials['permit']);
    if ($decodedPerms === false) {
        return false; // Invalid base64
    }

    $permissions = explode(',', $decodedPerms);

    return in_array('all', $permissions) || in_array('phonebook', $permissions) || in_array('campaigns', $permissions);
}

function cors() {
  // Allow from any origin
  if (isset($_SERVER['HTTP_ORIGIN'])) {
    // Decide if the origin in $_SERVER['HTTP_ORIGIN'] is one
    // you want to allow, and if so:
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');  // cache for 1 day
  }
  // Access-Control headers are received during OPTIONS requests
  if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
      // may also be using PUT, PATCH, HEAD etc
      header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    }
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
      header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    }
    exit(0);
  }
}

function handle_api_error($e) {
    error_log("API Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());

    // Return generic error message to client
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(array('error' => 'Internal server error'));
    exit;
}

// Set exception handler
set_exception_handler('handle_api_error');

// Set error handler for non-fatal errors
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

?>
