<?php
/*
Plugin Name: Slickplan Importer
Plugin URI: http://wordpress.org/extend/plugins/slickplan-importer/
Description: Import pages from a Slickplan's XML export file. To use go to the Tools -> Import screen and click on Slickplan.
Author: slickplan.com
Author URI: http://slickplan.com/
Version: 0.2
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
        private $_default_data = array(
            'post_status' => 'publish',
            'post_content' => '',
            'post_type' => 'page',
            // 'post_parent' => '',
            // 'post_title' => '',
        );
        private $_import_notes = false;

        public function dispatch()
        {
            $step = (int) (isset($_GET['step']) ? $_GET['step'] : 0);
            echo '<div class="wrap">';
            screen_icon();
            echo '<h2>' . __('Import Slickplan\'s XML', 'slickplan-importer') . '</h2>';
            switch ($step) {
                case 0:
                    echo '<div class="narrow">';
                    if (function_exists('simplexml_load_file')) {
                        echo '<p>' . __('This importer allows you to import pages structure from a Slickplan\'s XML export file into your WordPress site. Pick an XML file to upload and click Import.', 'slickplan-importer') . '</p>';
                        ob_start();
                        wp_import_upload_form('admin.php?import=slickplan&step=1');
                        $form = ob_get_contents();
                        ob_end_clean();
                        if (strpos($form, '</p>') !== false){
                            $form = substr_replace($form, '<p><label for="importnotes"><input type="checkbox" name="importnotes" id="importnotes" value="2"> ' . __('Import Notes as Pages Contents', 'slickplan-importer') . '</label></p>', strpos($form, '</p>') + 4, 0);
                        } 
                        echo $form;
                    }
                    else {
                        echo '<p>' . __('Sorry! This importer requires the libxml and SimpleXML PHP extensions.', 'slickplan-importer') . '</p>';
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

        private function _fetch_pages($data, $parent_id = 0)
        {
            $parent_id = (int) $parent_id;
            foreach ($data as $item) {
                $page = $this->_default_data;
                $page['post_title'] = (string) $item->title;
                if ($this->_import_notes) {
                    $page['post_content'] = (string) $item->description;
                }
                if ($parent_id) {
                    $page['post_parent'] = $parent_id;
                }
                echo '<li>' . __('Importing page... ', 'slickplan-importer');
                echo $page['post_title'];
                $page_id = wp_insert_post($page);
                if (is_wp_error($page_id)) {
                    return $page_id;
                }
                if (!$page_id) {
                    echo '<span style="color: #d00">';
                    _e('Couldn\'t get post ID', 'slickplan-importer');
                    echo '</span>';
                    return;
                }
                echo '<span style="color: #080">';
                _e(' - Done!', 'slickplan-importer');
                echo '</span>';
                if (isset($item->child->item)) {
                    $this->_fetch_pages($item->child->item, $page_id);
                }
            }
        }

        private function _import_pages()
        {
            echo '<ol>';
            if (isset($this->_xml->home->title)) {
                $page = $this->_default_data;
                $page['post_title'] = (string) $this->_xml->home->title;
                echo '<li>' . __('Importing page... ', 'slickplan-importer');
                echo $page['post_title'];
                $page_id = wp_insert_post($page);
                if (is_wp_error($page_id)) {
                    return $page_id;
                }
                if (!$page_id) {
                    echo '<span style="color: #d00">';
                    _e('Couldn\'t get post ID', 'slickplan-importer');
                    echo '</span>';
                    return;
                }
                echo '<span style="color: #080">';
                _e(' - Done!', 'slickplan-importer');
                echo '</span>';
            }
            if (isset($this->_xml->utilities->item)) {
                $this->_fetch_pages($this->_xml->utilities->item);
            }
            if (isset($this->_xml->items->item)) {
                $this->_fetch_pages($this->_xml->items->item);
            }
            if (isset($this->_xml->footer->item)) {
                $this->_fetch_pages($this->_xml->footer->item);
            }
            echo '</ol>';
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
            if (isset($this->_xml->link, $this->_xml->items, $this->_xml->title, $this->_xml->version) and strstr($this->_xml->link, 'slickplan')) {
                $result = $this->_import_pages();
            }
            else {
                _e('Incorrect XML file format.', 'slickplan-importer');
                return;
            }
            if (is_wp_error($result)) {
                return $result;
            }
            wp_import_cleanup($file['id']);
            do_action('import_done', 'slickplan');
            echo '<h3>';
            printf(__('All done. <a href="%s">Have fun!</a>', 'slickplan-importer'), get_admin_url(null, 'edit.php?post_type=page'));
            echo '</h3>';
        }

    }

    $slickplan_import = new Slickplan_Import();
    register_importer('slickplan', __('Slickplan', 'slickplan-importer'), __('Import pages from a Slickplan\'s XML export file.', 'slickplan-importer'), array($slickplan_import, 'dispatch'));
}
