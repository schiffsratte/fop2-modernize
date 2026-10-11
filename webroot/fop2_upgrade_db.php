<?php
if (!defined('ALLOW_ACCESS')) {
    header('HTTP/1.0 403 Forbidden');
    exit('Direct access not permitted');
}

$ris = $db->consulta("SET NAMES utf8mb4");

$querycreate = array();

// START DATABASE/TABLES SETUP AND UPGRADE
$querycreate[] = "CREATE TABLE IF NOT EXISTS `visual_phonebook` (
    `id` int not null auto_increment,
    `firstname` varchar(50) default null,
    `lastname`  varchar(50) default null,
    `company`   varchar(100) default null,
    `owner`     varchar(50) default '',
    `private`   enum('yes','no') default 'no',
    `picture`   varchar(250) default null,
    `email`     varchar(150) default '',
    `instagram` varchar(64) default '',
    `telegram`  varchar(64) default '',
    `address`   varchar(150) default '',
    `position`  varchar(100) default '',
    `identification` varchar(100) default '',
    `context`   varchar(150) default '',
    primary key  (`id`),
    UNIQUE KEY `fullname` (`firstname`,`lastname`),
    key `search` (`firstname`,`lastname`,`company`),
    KEY `ide` (`identification`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$querycreate[] = "CREATE TABLE IF NOT EXISTS campaign_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    variables TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    owner VARCHAR(50) DEFAULT '',
    context VARCHAR(150) DEFAULT '',
    active TINYINT(1) DEFAULT 1,
    INDEX idx_owner (owner),
    INDEX idx_active (active),
    INDEX idx_owner_active_context (owner,active,context),
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$querycreate[] = "CREATE TABLE IF NOT EXISTS fop2_notes ( 
    id int(11) auto_increment primary key not null, 
    contact_id int(11), 
    datetime timestamp, 
    note text, 
    author varchar(30), 
    INDEX cid(contact_id), 
    INDEX auth(author)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$querycreate[] = "CREATE TABLE IF NOT EXISTS `visual_phonebook_phones` (
    `contact_id` int ,
    `type` enum('home','work','cell','fax','other','main','whatsapp','sms') DEFAULT 'other',
    `number` varchar(50) DEFAULT NULL,
    INDEX cid (contact_id),
    FOREIGN KEY(contact_id) REFERENCES visual_phonebook(id) ON DELETE CASCADE
) ENGINE InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$querycreate[] = "CREATE TABLE IF NOT EXISTS `visual_phonebook_tags` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `context` varchar(150) DEFAULT '',
    `tag` varchar(190) DEFAULT NULL,
    `color` varchar(20) DEFAULT '',
    PRIMARY KEY (`id`),
    UNIQUE KEY `mytag` (`tag`)
) ENGINE InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$querycreate[] = "CREATE TABLE IF NOT EXISTS `visual_phonebook_tags_contacts` (
    `contact_id` int ,
    `tag_id` int,
    INDEX conid (contact_id),
    INDEX catid (tag_id),
    FOREIGN KEY(contact_id) REFERENCES visual_phonebook(id) ON DELETE CASCADE,
    FOREIGN KEY(tag_id) REFERENCES visual_phonebook_tags(id) ON DELETE CASCADE
) ENGINE InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

foreach($querycreate as $createTableQuery) {
    $db->consulta($createTableQuery);
}

// Update fields from older versions
$alldbfields   = array();
$alldbfields['visual_phonebook']['email']          = "ALTER TABLE `visual_phonebook` ADD `email` varchar(150) DEFAULT '' AFTER phone2";
$alldbfields['visual_phonebook']['address']        = "ALTER TABLE `visual_phonebook` ADD `address` varchar(150) DEFAULT '' AFTER email";
$alldbfields['visual_phonebook']['identification'] = "ALTER TABLE `visual_phonebook` ADD `identification` varchar(100) DEFAULT '' AFTER address";
$alldbfields['visual_phonebook']['position']       = "ALTER TABLE `visual_phonebook` ADD `position` varchar(100) DEFAULT '' AFTER address";
$alldbfields['visual_phonebook']['instagram']      = "ALTER TABLE `visual_phonebook` ADD `instagram` varchar(64) DEFAULT '' AFTER email";
$alldbfields['visual_phonebook']['telegram']       = "ALTER TABLE `visual_phonebook` ADD `telegram` varchar(64) DEFAULT '' AFTER instagram";

foreach($alldbfields as $table => $rest) {
    $res = $db->consulta("DESC $table");
    if($res) {
        // table exists, check if we need to add /update_fields to it
        $existdbfield = array();
        while($row = $db->fetch_assoc($res)) {
            $campo = $row['Field'];
            $existdbfield[$campo]=1;
        }
        foreach($rest as $campo=>$query) {
            if(!isset($existdbfield[$campo])) {
                $ris = $db->consulta($query);
            }
        }
    }
}

// Define all indexes to check and create
$indexesToCheck = array(
    'visual_phonebook' => array(
        'idx_vp_context_owner_private' => "ADD INDEX idx_vp_context_owner_private (context, owner, private)",
        'idx_vp_firstname' => "ADD INDEX idx_vp_firstname (firstname)",
        'idx_vp_lastname' => "ADD INDEX idx_vp_lastname (lastname)",
        'idx_vp_company' => "ADD INDEX idx_vp_company (company)",
        'fullname' => "ADD UNIQUE INDEX fullname (firstname, lastname)",
        'ide' => "ADD INDEX ide (identification)"
    ),
    'visual_phonebook_phones' => array(
        'idx_vpp_number' => "ADD INDEX idx_vpp_number (number)",
        'idx_vpp_contact_id' => "ADD INDEX idx_vpp_contact_id (contact_id)"
    ),
    'visual_phonebook_tags_contacts' => array(
        'idx_vptc_tag_contact' => "ADD INDEX idx_vptc_tag_contact (tag_id, contact_id)"
    ),
    'visual_phonebook_tags' => array(
        'idx_vpt_context_tag' => "ADD INDEX idx_vpt_context_tag (context, tag)"
    )
);

foreach ($indexesToCheck as $table => $indexes) {
    // Get existing indexes for the table
    $existingIndexes = array();
    $res = $db->consulta("SHOW INDEX FROM $table");
    if ($res) {
        while ($row = $db->fetch_assoc($res)) {
            $existingIndexes[$row['Key_name']] = true;
        }
    }
    // Create missing indexes
    foreach ($indexes as $indexName => $sqlFragment) {
        if (!isset($existingIndexes[$indexName])) {
            $sql = "ALTER TABLE $table $sqlFragment";
            $db->consulta($sql);
        }
    }
}

