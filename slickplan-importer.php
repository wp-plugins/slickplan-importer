<?php
/*
Plugin Name: Slickplan Importer
Plugin URI: http://wordpress.org/extend/plugins/slickplan-importer/
Description: Import pages from a Slickplan's XML export file. To use go to the <a href="import.php">Tools -> Import</a> screen and select Slickplan.
Author: slickplan.com
Author URI: http://slickplan.com/
Version: 0.3
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

if (!defined('WP_LOAD_IMPORTERS')) {
    return;
}

require_once ABSPATH . 'wp-admin/includes/import.php';

if (!class_exists('WP_Importer')) {
    $class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
    if (file_exists($class_wp_importer)) {
        require_once $class_wp_importer;
    }
}

if (class_exists('WP_Importer')) {

    class Slickplan_Import extends WP_Importer {

        private $_xml;
        private $_default_data = array();
        private $_import_notes = false;
        private $_import_sections = 4;
        private $_sitemap = array();
        private $_parsed_array;
        private $_sections_list = array();

        public function __construct()
        {
            $this->_default_data = array(
                'post_status' => 'publish',
                'post_content' => '',
                'post_type' => 'page',
            );
            parent::__construct();
        }

        public function dispatch()
        {
            $step = (int) (isset($_GET['step']) ? $_GET['step'] : 0);
            echo '<div class="wrap">';
            screen_icon();
            echo '<h2>Import Slickplan\'s XML</h2>';
            switch ($step) {
                case 0:
                    echo '<div class="narrow">';
                    if (function_exists('simplexml_load_file')) {
                        echo '<p>This importer allows you to import pages structure from a Slickplan\'s XML export file into your WordPress site. Pick an XML file to upload and click Import.</p>';
                        ob_start();
                        wp_import_upload_form('admin.php?import=slickplan&step=1');
                        $form = ob_get_contents();
                        ob_end_clean();
                        if (strpos($form, '</p>') !== false){
                            $form = substr_replace($form,
                                '<p><label for="importnotes"><input type="checkbox" name="importnotes" id="importnotes" value="2"> Import Notes as Pages Contents</label></p>'
                              . '<p>Sections:<br /><label for="importsections_2"><input type="radio" name="importsections" id="importsections_2" value="2" checked="checked"> Import as Child Pages</label><br />'
                              . '<label for="importsections_3"><input type="radio" name="importsections" id="importsections_3" value="3"> Import as Pages</label><br />'
                              . '<label for="importsections_4"><input type="radio" name="importsections" id="importsections_4" value="4"> Do Not Import</label></p>'
                            , strpos($form, '</p>') + 4, 0);
                        } 
                        echo $form;
                    }
                    else {
                        echo '<p>Sorry! This importer requires the libxml and SimpleXML PHP extensions.</p>';
                    }
                    echo '</div>';
                    break;
                case 1:
                    check_admin_referer('import-upload');
                    $result = $this->_import();
                    if (is_wp_error($result)) {
                        echo $result->get_error_message();
                    }
                    break;
            }
            echo '</div>';
        }

        private function _create_page($item, $parent_id = 0)
        {
            $page = $this->_default_data;
            $page['post_title'] = (string) $item['name'];
            $label = 'Importing page';
            if ($this->_import_notes and isset($item['data']['note']) and !empty($item['data']['note'])) {
                $page['post_content'] = (string) $item['data']['note'];
                $label .= ' with content';
            }
            if ($parent_id) {
                $page['post_parent'] = $parent_id;
                $label .= ' (child of ' . $parent_id . ')';
            }
            echo '<li>' . $label . '&hellip; ';
            $page_id = wp_insert_post($page);
            if (is_wp_error($page_id) or !$page_id) {
                echo $page['post_title'];
                echo '<span style="color: #d00"> - Error!</span>';
                return false;
            }
            echo '<a href="' . get_admin_url(null, 'post.php?post=' . $page_id . '&action=edit') . '">' . $page['post_title'] . ' (ID: ' . $page_id . ')</a>';
            echo '<span style="color: #080"> - Done!</span>';
            if (isset($item['child'])) {
                foreach ($item['child'] as $child) {
                    $this->_create_page($child, $page_id);
                }
            }
            if ($this->_import_sections === 2 and isset($item['data']['section'])) {
                $section = (int) $item['data']['section'];
                if (isset($this->_sitemap[$section])) {
                    foreach ($this->_sitemap[$section] as $child) {
                        $this->_create_page($child, $page_id);
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
            set_magic_quotes_runtime(0);
            if (function_exists('libxml_use_internal_errors')) {
                libxml_use_internal_errors(true);
            }
            $this->_xml = simplexml_load_file($file['file']);
            $this->_import_notes = (isset($_POST['importnotes']) and intval($_POST['importnotes']) === 2);
            $this->_import_sections = isset($_POST['importsections']) ? intval($_POST['importsections']) : 4;
            if (isset($this->_xml->link, $this->_xml->title, $this->_xml->version) and strstr($this->_xml->link, 'slickplan')) {
                echo '<ol>';
                $this->_parse_slickplan($this->_xml);
                foreach ($this->_sitemap as $key => $array) {
                    if ($key > 0 and $this->_import_sections !== 3) {
                        break;
                    }
                    foreach ($array as $item) {
                        $this->_create_page($item);
                    }
                }
                echo '</ol>';
            }
            else {
                echo 'Incorrect XML file format.';
                return;
            }
            wp_import_cleanup($file['id']);
            do_action('import_done', 'slickplan');
            echo '<h3>';
            printf('All done. <a href="%s">Have fun!</a>', get_admin_url(null, 'edit.php?post_type=page'));
            echo '</h3>';
        }

        private function _parse_slickplan(&$xml)
        {
            $this->_sitemap = array();
            $sections = isset($xml->section) ? $xml->section : array(0 => $xml);
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

    }

    $slickplan_import = new Slickplan_Import();
    register_importer('slickplan', 'Slickplan', 'Import pages from a Slickplan\'s XML export file.', array($slickplan_import, 'dispatch'));
}
