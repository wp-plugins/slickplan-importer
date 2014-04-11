<?php
/*
Plugin Name: Slickplan Importer
Plugin URI: http://wordpress.org/extend/plugins/slickplan-importer/
Description: Import pages from a <a href="http://slickplan.com" target="_blank">Slickplan</a>'s XML export file. To use go to the <a href="import.php">Tools -> Import</a> screen and select Slickplan.
Author: slickplan.com
Author URI: http://slickplan.com/
Version: 1.0
License: GNU General Public License Version 3 - http://www.gnu.org/licenses/gpl-3.0.html
*/

if (!defined('WP_LOAD_IMPORTERS')) {
    return;
}

require_once ABSPATH . 'wp-admin/includes/import.php';

if (!class_exists('WP_Importer')) {
    $class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
    if (is_file($class_wp_importer)) {
        require_once $class_wp_importer;
    }
}

if (class_exists('WP_Importer') and !class_exists('Slickplan_Import')) {

    class Slickplan_Import extends WP_Importer {

        const PAGE_TITLE_NO_CHANGE = 0;
        const PAGE_TITLE_UPPERCASE_FIRST = 3;
        const PAGE_TITLE_UPPERCASE_WORDS = 4;

        const SECTIONS_IMPORT_AS_CHILD = 2;
        const SECTIONS_IMPORT_AS_NEW_PAGES = 3;
        const SECTIONS_IMPORT_STOP = 0;

        private $_uri;
        private $_xml;
        private $_default_data = array();
        private $_import_notes = false;
        private $_ignore_external = false;
        private $_import_sections;
        private $_page_title_mode;
        private $_sitemap = array();
        private $_parsed_array;
        private $_sections_list = array();
        private $_errors = array();

        public function __construct()
        {
            ob_start();

            $this->_default_data = array(
                'post_status' => 'publish',
                'post_content' => '',
                'post_type' => 'page',
            );
            $this->_import_sections = self::SECTIONS_IMPORT_STOP;
            $this->_page_title_mode = self::PAGE_TITLE_NO_CHANGE;

            $this->_uri = get_admin_url(null, 'admin.php?import=slickplan');

            $this->_errors = array(
                2 => 'Incorrect XML file format.',
            );

            parent::__construct();
        }

        public function dispatch()
        {
            echo '<div class="wrap"><h2>Import Slickplan\'s XML</h2>';
            if (isset($_GET['error'], $this->_errors[$_GET['error']]) and $this->_errors[$_GET['error']]) {
                echo '<div class="error"><p>' . $this->_errors[$_GET['error']] . '</p></div>';
            }
            if (isset($_FILES['import']) and $_FILES['import']) {
                check_admin_referer('import-upload');
                $result = $this->_import();
                if (is_wp_error($result)) {
                    echo $result->get_error_message();
                }
            }
            else {
                if (function_exists('simplexml_load_file')) {
                    echo '<div class="updated" style="border-color: #FFBA00"><p>This importer allows you to import pages structure from a <a href="http://slickplan.com" target="_blank">Slickplan</a>\'s XML export file into your WordPress site. Pick a XML file to upload and click Import.</p></div>';
                    ob_start();
                    wp_import_upload_form($this->_uri);
                    $form = ob_get_contents();
                    ob_end_clean();
                    if (strpos($form, '</p>') !== false){
                        $form = substr_replace($form, $this->_get_form_options(), strpos($form, '</p>') + 4, 0);
                    } 
                    echo '<div class="narrow">' . $form . '</div>';
                }
                else {
                    echo '<div class="error"><p>Sorry! This importer requires the libxml and SimpleXML PHP extensions.</p></div>';
                }
            }
            echo '</div>';
        }

        private function _create_page($item, $parent_id = 0)
        {
            $page = $this->_default_data;
            $page['post_title'] = (string) $item['text'];
            $page['post_title'] = $this->_parse_title($page['post_title']);
            $label = 'Importing page';
            if ($this->_import_notes and isset($item['desc']) and !empty($item['desc'])) {
                $page['post_content'] = (string) $item['desc'];
                $label .= ' and content';
            }
            if (!$parent_id and isset($item['parent'], $this->_posts_parents[$item['parent']])) {
                $parent_id = $this->_posts_parents[$item['parent']];
            }
            if ($parent_id) {
                if (is_int($parent_id)) {
                    $page['post_parent'] = $parent_id;
                    $label .= ' (child of ' . $parent_id . ')';
                }
                else if (isset($this->_posts_parents[$parent_id])) {
                    $parent_id = $this->_posts_parents[$parent_id];
                    $page['post_parent'] = $parent_id;
                    $label .= ' (child of ' . $parent_id . ')';
                }
            }
            echo '<li>' . $label . '&hellip; ';
            $page_id = wp_insert_post($page);
            if (is_wp_error($page_id) or !$page_id) {
                echo $page['post_title'];
                echo '<span style="color: #d00"> - Error! (' . $page_id->get_error_message() . ')</span>';
                return false;
            }
            $this->_posts_parents[$item['id']] = (int) $page_id;
            echo '<a href="' . get_admin_url(null, 'post.php?post=' . $page_id . '&action=edit') . '">' . $page['post_title'] . ' (ID: ' . $page_id . ')</a>';
            echo '<span style="color: #080"> - Done!</span>';
            if ($this->_import_sections === self::SECTIONS_IMPORT_AS_CHILD and isset($item['section'], $this->_sitemap[$item['section']])) {
                foreach ($this->_sitemap[$item['section']] as $child) {
                    $this->_create_page($child, $page_id);
                }
            }
        }

        private function _create_page_old($item, $parent_id = 0)
        {
            $page = $this->_default_data;
            $page['post_title'] = (string) $item['name'];
            $page['post_title'] = $this->_parse_title($page['post_title']);
            $label = 'Importing page';
            if ($this->_import_notes and isset($item['data']['note']) and !empty($item['data']['note'])) {
                $page['post_content'] = (string) $item['data']['note'];
                $label .= ' and content';
            }
            if ($parent_id) {
                $page['post_parent'] = $parent_id;
                $label .= ' (child of ' . $parent_id . ')';
            }
            echo '<li>' . $label . '&hellip; ';
            $page_id = wp_insert_post($page);
            if (is_wp_error($page_id) or !$page_id) {
                echo $page['post_title'];
                echo '<span style="color: #d00"> - Error! (' . $page_id->get_error_message() . ')</span>';
                return false;
            }
            echo '<a href="' . get_admin_url(null, 'post.php?post=' . $page_id . '&action=edit') . '">' . $page['post_title'] . ' (ID: ' . $page_id . ')</a>';
            echo '<span style="color: #080"> - Done!</span>';
            if (isset($item['child'])) {
                foreach ($item['child'] as $child) {
                    $this->_create_page_old($child, $page_id);
                }
            }
            if ($this->_import_sections === self::SECTIONS_IMPORT_AS_CHILD and isset($item['data']['section'])) {
                $section = (int) $item['data']['section'];
                if (isset($this->_sitemap[$section])) {
                    foreach ($this->_sitemap[$section] as $child) {
                        $this->_create_page_old($child, $page_id);
                    }
                }
            }
        }

        private function _import()
        {
            $file = wp_import_handle_upload();
            if (isset($file['error'])) {
                echo $file['error'];
                return;
            }
            if (function_exists('set_magic_quotes_runtime') and version_compare(PHP_VERSION, '5.3.0', '<')) {
                set_magic_quotes_runtime(0);
            }
            if (function_exists('libxml_use_internal_errors')) {
                libxml_use_internal_errors(true);
            }
            $this->_xml = simplexml_load_file($file['file']);
            $this->_import_notes = (isset($_POST['importnotes']) and $_POST['importnotes']);
            $this->_ignore_external = (isset($_POST['excludeexternal']) and $_POST['excludeexternal']);
            $this->_import_sections = isset($_POST['importsections']) ? intval($_POST['importsections']) : self::SECTIONS_IMPORT_STOP;
            $this->_page_title_mode = isset($_POST['importpagetitle']) ? intval($_POST['importpagetitle']) : self::PAGE_TITLE_NO_CHANGE;
            if (isset($this->_xml->link, $this->_xml->title, $this->_xml->version) and strstr($this->_xml->link, 'slickplan')) {
                echo '<ol>';
                $this->_sitemap = array();
                $sections = isset($this->_xml->section) ? $this->_xml->section : array(0 => $this->_xml);
                $slickplan_new_xml = false;
                foreach ($sections as $xml) {
                    $attributes = (array) $xml->attributes();
                    if (isset($attributes['@attributes']['id'])) {
                        if ($attributes['@attributes']['id'] === 'svgmainsection') {
                            $slickplan_new_xml = true;
                            break;
                        }
                    }
                }
                if ($slickplan_new_xml) {
                    $this->_parse_slickplan($sections);
                    foreach ($this->_sitemap as $key => $array) {
                        if ($key !== 'svgmainsection' and $this->_import_sections !== self::SECTIONS_IMPORT_AS_NEW_PAGES) {
                            break;
                        }
                        foreach ($array as $item) {
                            if (isset($item['archetype']) and $item['archetype'] === 'external' and $this->_ignore_external) {
                                continue;
                            }
                            $this->_create_page($item);
                        }
                    }
                }
                else {
                    $this->_parse_slickplan_old($sections);
                    foreach ($this->_sitemap as $key => $array) {
                        if ($key > 0 and $this->_import_sections !== self::SECTIONS_IMPORT_AS_NEW_PAGES) {
                            break;
                        }
                        foreach ($array as $item) {
                            if (isset($item['data']['archetype']) and $item['data']['archetype'] === 'external' and $this->_ignore_external) {
                                continue;
                            }
                            $this->_create_page_old($item);
                        }
                    }
                }
                echo '</ol>';
            }
            else {
                wp_redirect($this->_uri . '&error=2');
                return;
            }
            wp_import_cleanup($file['id']);
            do_action('import_done', 'slickplan');
            
            printf('<div class="updated"><p>All done. <a href="%s">Have fun!</a></p></div>', get_admin_url(null, 'edit.php?post_type=page'));
        }

        private function _parse_slickplan($sections)
        {
            foreach ($sections as $xml) {
                $attributes = (array) $xml->attributes();
                if (isset($attributes['@attributes']['id'])) {
                    $key = $attributes['@attributes']['id'];
                }
                else {
                    $key = uniqid();
                }
                if (isset($xml->cells->cell)) {
                    $this->_sitemap[$key] = array();
                    foreach ($xml->cells->cell as $cell) {
                        $attributes = (array) $cell->attributes();
                        $cell_id = isset($attributes['@attributes']['id']) ? $attributes['@attributes']['id'] : uniqid();
                        $this->_sitemap[$key][$cell_id] = array(
                            'id' => $cell_id,
                        );
                        $cell_a = (array) $cell;
                        foreach ($cell_a as $attr => $value) {
                            if ($attr{0} === '@') {
                                continue;
                            }
                            if (!$value->count()) {
                                $this->_sitemap[$key][$cell_id][$attr] = (string) $value;
                            }
                        }
                    }
                }
                uasort($this->_sitemap[$key], 'slickplan_importer_cells_uasort');
            }
            return $this->_sitemap;
        }

        private function _parse_slickplan_old($sections)
        {
            foreach ($sections as $xml) {
                $attributes = (array) $xml->attributes();
                if (isset($attributes['@attributes']['id'])) {
                    $this->_sections_list[] = (int) $attributes['@attributes']['id'];
                }
            }
            $key = -1;
            foreach ($sections as $xml) {
                $attributes = (array) $xml->attributes();
                if (isset($attributes['@attributes']['id'])) {
                    $key = (int) $attributes['@attributes']['id'];
                }
                else {
                    do {
                        ++$key;
                    } while (isset($this->_sitemap[$key]));
                }
                $this->_sitemap[$key] = array();
                if ($key === 0 and isset($xml->utilities->item)) {
                    $this->_sitemap[$key] = array_merge($this->_sitemap[$key], $this->_get_slickplan_cells($xml->utilities->item));
                }
                elseif ($key === 0 and isset($xml->utility->item)) {
                    $this->_sitemap[$key] = array_merge($this->_sitemap[$key], $this->_get_slickplan_cells($xml->utility->item));
                }
                if ($key === 0 and isset($xml->footer->item)) {
                    $this->_sitemap[$key] = array_merge($this->_sitemap[$key], $this->_get_slickplan_cells($xml->footer->item));
                }
                if (isset($xml->items)) {
                    if ($key === 0 and isset($xml->items->home->title)) {
                        $this->_sitemap[$key] = array_merge($this->_sitemap[$key], $this->_get_slickplan_cells($xml->items->home));
                    }
                    if (isset($xml->items->item)) {
                        $this->_sitemap[$key] = array_merge($this->_sitemap[$key], $this->_get_slickplan_cells($xml->items->item));
                    }
                }
                else if (isset($xml->main)) {
                    if ($key === 0 and isset($xml->main->home->title)) {
                        $this->_sitemap[$key] = array_merge($this->_sitemap[$key], $this->_get_slickplan_cells($xml->main->home));
                    }
                    if (isset($xml->main->item)) {
                        $this->_sitemap[$key] = array_merge($this->_sitemap[$key], $this->_get_slickplan_cells($xml->main->item));
                    }
                }
            }
            $this->_sitemap = (array) $this->_sitemap;
            return $this->_sitemap;
        }

        private function _get_slickplan_cells($data)
        {
            $return = array();
            foreach ($data as $item) {
                $cell = array('name' => (string) $item->title);
                if (isset($item->description)) {
                    $cell['data']['note'] = (string) $item->description;
                }
                if (isset($item->archetype)) {
                    $cell['data']['archetype'] = (string) $item->archetype;
                }
                if (isset($item->section) and intval($item->section) > 0 and in_array(intval($item->section), $this->_sections_list, true)) {
                    $cell['data']['section'] = (int) $item->section;
                }
                if (isset($item->child->item)) {
                    $cell['child'] = $this->_get_slickplan_cells($item->child->item);
                }
                $return[] = $cell;
            }
            return $return;
        }

        private function _parse_title($title)
        {
            if ($this->_page_title_mode === self::PAGE_TITLE_UPPERCASE_FIRST) {
                if (function_exists('mb_strtolower')) {
                    $title = mb_strtolower($title);
                    $title = mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1);
                }
                else {
                    $title = ucfirst(strtolower($title));
                }
            }
            else if ($this->_page_title_mode === self::PAGE_TITLE_UPPERCASE_WORDS) {
                if (function_exists('mb_convert_case')) {
                    $title = mb_convert_case($title, MB_CASE_TITLE);
                }
                else {
                    $title = ucwords(strtolower($title));
                }
            }
            return $title;
        }

        private function _get_form_options()
        {
            $page_title_no_change = self::PAGE_TITLE_NO_CHANGE;
            $page_title_uppercase_first = self::PAGE_TITLE_UPPERCASE_FIRST;
            $page_title_uppercase_words = self::PAGE_TITLE_UPPERCASE_WORDS;

            $sections_import_as_child = self::SECTIONS_IMPORT_AS_CHILD;
            $sections_import_as_new_pages = self::SECTIONS_IMPORT_AS_NEW_PAGES;
            $sections_import_stop = self::SECTIONS_IMPORT_STOP;

            return <<<EOC
<table class="form-table">
    <tbody>
        <tr valign="top">
            <th scope="row">Import Settings</th>
            <td>
                <fieldset>
                    <legend class="screen-reader-text"><span>Import Settings</span></legend>
                    <label for="importnotes">
                        <input type="checkbox" name="importnotes" id="importnotes" value="2">
                        Import notes as pages contents
                    </label>
                    <br>
                    <label for="excludeexternal">
                        <input type="checkbox" name="excludeexternal" id="excludeexternal" value="2">
                        Ignore pages marked as 'External' page type
                    </label>
                    <p class="description">This will also ignore all child pages and sections of the 'External' pages</p>
                </fieldset>
            </td>
        </tr>
        <tr valign="top">
            <th scope="row">Pages Titles</th>
            <td>
                <fieldset>
                    <legend class="screen-reader-text"><span>Pages Titles Modification</span></legend>
                    <label for="importpagetitle1">
                        <input type="radio" name="importpagetitle" id="importpagetitle1" value="{$page_title_no_change}" checked="checked">
                        No change
                    </label>
                    <br>
                    <label for="importpagetitle2">
                        <input type="radio" name="importpagetitle" id="importpagetitle2" value="{$page_title_uppercase_first}" checked="checked">
                        Make just the first character uppercase:
                    </label>
                    <p class="description">This is an example page title</p>
                    <br>
                    <label for="importpagetitle3">
                        <input type="radio" name="importpagetitle" id="importpagetitle3" value="{$page_title_uppercase_words}" checked="checked">
                        Uppercase the first character of each word:
                    </label>
                    <p class="description">This Is An Example Page Title</p>
                </fieldset>
            </td>
        </tr>
        <tr valign="top">
            <th scope="row">Sections</th>
            <td>
                <fieldset>
                    <legend class="screen-reader-text"><span>Sections</span></legend>
                    <label for="importsections1">
                        <input type="radio" name="importsections" id="importsections1" value="{$sections_import_as_child}" checked="checked">
                        Import as child pages
                    </label>
                    <br>
                    <label for="importsections2">
                        <input type="radio" name="importsections" id="importsections2" value="{$sections_import_as_new_pages}">
                        Import as pages
                    </label>
                    <br>
                    <label for="importsections3">
                        <input type="radio" name="importsections" id="importsections3" value="{$sections_import_stop}">
                        Do not import
                    </label>
                    <br>
                </fieldset>
            </td>
        </tr>
    </tbody>
</table>
EOC;
        }

    }

    $slickplan_import = new Slickplan_Import;
    register_importer('slickplan', 'Slickplan', 'Import pages from a <a href="http://slickplan.com" target="_blank">Slickplan</a>\'s XML export file.', array($slickplan_import, 'dispatch'));

    if (!function_exists('slickplan_importer_cells_uasort')) {
        function slickplan_importer_cells_uasort($a, $b) {
            if (!isset($a['level']) or !isset($b['level'])) {
                return -1;
            }
            $a_lvl = (int) (ctype_digit((string) $a['level']) ? $a['level'] : 0);
            $b_lvl = (int) (ctype_digit((string) $b['level']) ? $b['level'] : 0);
            if ($a_lvl === $b_lvl) {
                $a_order = (int) (isset($a['order']) ? $a['order'] : 99999);
                $b_order = (int) (isset($b['order']) ? $b['order'] : 99999);
                return ($b_order < $a_order) ? 1 : -1;
            }
            else {
                return ($b_lvl < $a_lvl) ? 1 : -1;
            }
        }
    }

}