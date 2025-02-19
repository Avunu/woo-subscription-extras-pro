<?php
if ( !class_exists('MHUpgrader') ) {
    class MHUpgrader {
        private $pluginAlias;
        private $pluginBaseFile;
        private $pluginTitle;
        private $version;

        /**
         * @var MHNotice
         */
        private $noticeObject;

        public function __construct($pluginAlias, $pluginBaseFile, $pluginTitle, $pluginAbbrev, $noticeObject) {
            $this->pluginAlias = $pluginAlias;
            $this->pluginBaseFile = $pluginBaseFile;
            $this->pluginTitle = $pluginTitle;
            $this->noticeObject = $noticeObject;

            $pluginFile = $this->getPluginFile();

            add_filter('plugin_action_links_' . plugin_basename($pluginBaseFile), array($this, 'pluginLinks') );
            add_action('admin_menu', array($this, 'registerPage'));
            add_action('admin_title', array($this, 'editPageTitle'));
            add_action('upgrader_process_complete', array($this, 'onUpgradeCompleted'), 10, 2 );
            add_action("after_plugin_row_{$pluginFile}", array($this, 'checkPluginNotices'), 10, 2 );

            if ( !$this->getLicenseCode() && !empty($noticeObject) ) {
                $message  = esc_html__( 'Your license code was not configured.' );
                $message .= ' ' . $this->getUpgradeLink(esc_html__('Please configure now.'));

                $noticeObject->addNotice(MHCommon::LICENSE_CODE_EMPTY_NOTICE, 'warning', $message, 30);
            }
        }

        public function editPageTitle() {
            global $current_screen, $title;

            if ( !empty($current_screen->base) && ( $current_screen->base == 'admin_page_upgrade-' . $this->getPluginAlias() ) ) {
                $title = $this->pluginTitle . ' ' . esc_html__('Update');
            }

            return $title;  
        }

        public function registerPage() {
            add_submenu_page(
                null,
                esc_html__('Plugin upgrade'),
                esc_html__('Plugin upgrade'),
                'manage_options',
                'upgrade-' . $this->getPluginAlias(),
                array($this, 'upgradePageCallback')
            );
        }

        public function onUpgradeCompleted( $upgrader_object, $options ) {
            // after run wordpress plugin update, call this updater to check
            if ( !$this->getHasUpdate() && $this->canCheckUpdate() ) {
                try {
                    $this->requestRemoteUpdate();
                }
                catch (Exception $e) {}
            }
        }

        public function checkPluginNotices() {
            if ( $this->getHasUpdate() ) {
                $text = sprintf(
                    __( 'There is a new version available. <a href="%s">update now</a>.' ),
                    esc_url( $this->getUpgradeUrl() )
                );

                $text = wp_kses($text, array(
                    'a' => array('href' => array())
                ));

                $this->pluginTabNotice($text);
            }
        }

        private function pluginTabNotice($text) {
            ?>
            <tr class="plugin-update-tr active">
                <td colspan="3" class="plugin-update colspanchange">
                    <div class="update-message notice inline notice-warning notice-alt">
                        <p>
                            <?php echo $text; ?>
                        </p>
                </td>
            </tr>
            <?php
        }

        private function getPluginFile() {
            return basename($this->getPluginDir()) . '/' . basename($this->pluginBaseFile);
        }

        private function getPluginAlias() {
            return $this->pluginAlias;
        }

        private function getPluginDir() {
            return dirname($this->pluginBaseFile);
        }

        private function getVersion() {
            if ( empty($this->version) ) {
                $pluginStr = file_get_contents($this->pluginBaseFile);
                preg_match('/Version: (.*)\n/isU', $pluginStr, $match);

                if ( !empty($match[1]) ) {
                    $this->version = trim($match[1]);
                }
            }

            return $this->version;
        }

        private function getUpgradeUrl() {
            return admin_url('admin.php?page=upgrade-' . esc_attr($this->getPluginAlias()));
        }

        public function pluginLinks($links) {
            $newLinks = array();
            $newLinks[] = $this->getUpgradeLink();

            if ( $this->hasChangelog() ) {
                $newLinks[] = $this->getChangelogLink();
            }

            return array_merge($newLinks, $links);
        }

        public function getUpgradeLink($label = null) {
            if ( !$label ) {
                $label = esc_html__('Update');
            }

            return sprintf('<a href="%s">%s</a>', esc_url($this->getUpgradeUrl()), $label);
        }

        public function getChangelogLink() {
            $label = esc_html__('Changelog');
            $plugin = $this->getPluginAlias();
            $logUrl = "http://pluggablesoft.com/premiumserver/getchangelog.php?plugin={$plugin}";
            return sprintf('<a href="%s" target="_blank">%s</a>', $logUrl, $label);
        }

        private function hasChangelog() {
            return file_exists($this->getChangelogFile());
        }

        private function getChangelogFile() {
            return $this->getPluginDir() . '/changelog.txt';
        }

        private function getOptions() {
            return get_option('mh_upgrader_' . $this->getPluginAlias(), array());
        }
        
        private function setOptions($options) {
            $options = array_merge($this->getOptions(), $options);
            return update_option('mh_upgrader_' . $this->getPluginAlias(), $options);
        }

        private function getLicenseCode() {
            $options = $this->getOptions();
            return !empty($options['license_key']) ? $options['license_key'] : null;
        }

        private function setLicenseCode($code) {
            if ( !empty($code) && !empty($this->noticeObject) ) {
                $this->noticeObject->removeNotice('license_empty');
            }

            return $this->setOptions(array(
                'license_key' => $code,
            ));
        }

        private function getUpdateError() {
            $options = $this->getOptions();
            return !empty($options['update_error']) ? $options['update_error'] : null;
        }

        private function setUpdateError($err) {
            return $this->setOptions(array(
                'update_error' => $err,
            ));
        }

        private function getHasUpdate() {
            $options = $this->getOptions();
            return !empty($options['has_update']) ? $options['has_update'] : false;
        }

        private function setHasUpdate($bool) {
            return $this->setOptions(array(
                'has_update' => $bool,
            ));
        }

        private function getPackageHash() {
            $options = $this->getOptions();
            return !empty($options['package_hash']) ? $options['package_hash'] : null;
        }

        private function setPackageHash($hash) {
            return $this->setOptions(array(
                'package_hash' => $hash,
            ));
        }

        private function canCheckUpdate() {
            return !get_transient($this->getPluginAlias() . '_updatecheck');
        }

        private function markUpdateCheckedFlag() {
            set_transient($this->getPluginAlias() . '_updatecheck', 'x', HOUR_IN_SECONDS);
        }

        private function requestRemoteUpdate($forceUpdate = false) {
            $this->markUpdateCheckedFlag();

            $url = 'http://pluggablesoft.com/premiumserver/request_update.php';

            $params = array(
                'plugin' => $this->getPluginAlias(),
                'version' => $this->getVersion(),
                'license' => $this->getLicenseCode(),
                'last_pkg_hash' => $this->getPackageHash(),
                'admin_email' => get_option('admin_email'),
                'site_url' => get_site_url(),
            );

            $response = wp_remote_post( $url, array(
                'method' => 'POST',
                'headers' => array(),
                'body' => $params,
            ));

            if ( $response instanceof WP_Error ) {
                throw new Exception( implode('<br/>', $response->get_error_messages()) );
            }

            if ( !is_array( $response ) ) {
                throw new Exception(esc_html__('Unknown response error.'));
            }

            $header = $response['headers']; // array of http header lines
            $body = $response['body']; // use the content

            $resp = json_decode($body);

            if ( !is_object($resp) ) {
                throw new Exception(esc_html__('Unknown update server response.'));
            }

            if ( !$resp->success ) {
                $err = $resp->message;

                // TODO: Make error code alias in server for this
                if ( preg_match('/Your license key has been expired/', $err) ) {
                    if ( !empty($this->noticeObject) ) {
                        $err = wp_kses($err, array('a' => array('href' => array(), 'target' => array())));

                        $this->noticeObject->addNotice('license_expired', 'warning', $err, 15);
                    }
                }

                throw new Exception($err);
            }

            if ( empty($resp->package) ) {
                throw new Exception(esc_html__('Package link not received from server.'));
            }

            if ( !empty($this->noticeObject) ) {
                $this->noticeObject->removeNotice('license_expired');
            }

            if ( !empty($resp->package_hash) ) {
                $this->setPackageHash($resp->package_hash);
            }

            if ( $resp->is_new ) {
                $this->setHasUpdate(true);
            }
            else {
                $this->setHasUpdate(false);

                if ( !$forceUpdate ) {
                    $forceUpdateUrl = $this->getUpgradeUrl() . '&force_update=1';

                    $forceUpdateLink = sprintf('<a href="%s">%s</a>', $forceUpdateUrl, esc_html__('Click here'));
                    $forceUpdateLink = wp_kses($forceUpdateLink, array('a' => array('href' => array())));
    
                    throw new MHAlreadyUpgradedException(sprintf(__('Your plugin version is up to date. %s to force an update.'), $forceUpdateLink));
                }
            }

            return $resp->package;
        }

        private function getRemotePackage($url) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            include_once ABSPATH . 'wp-admin/includes/theme.php';
            include_once ABSPATH . 'wp-admin/includes/admin.php';
            
            $upgrader = new WP_Upgrader;

            $getPkg = $upgrader->run(array(
                'package' => $url,
                'destination' => $this->getPluginDir(),
                'abort_if_destination_exists' => false,
            ));

            if ( $getPkg === false ) {
                throw new Exception(esc_html__('Filesystem error while updating.'));
            }

            if ( $getPkg instanceof WP_Error ) {
                $errors = implode('<br/>', $getPkg->get_error_messages());
                $errors = wp_kses($errors, array(
                    'br' => array()
                ));

                throw new Exception( $errors );
            }

            if ( !is_array($getPkg) ) {
                throw new Exception(esc_html__('Unknown error while getting the package.'));
            }

            return $getPkg;
        }

        private function btBackPlugins() {
            $url = admin_url('plugins.php');

            ?>
            <a href="<?php echo $url; ?>" class="button">
                <?php echo esc_html__('Back to Plugins') ?>
            </a>
            <?php
        }

        private function btResetLicense() {
            $url = $this->getUpgradeUrl() . '&reset_license_key=1';

            ?>
            <a href="<?php echo esc_url($url); ?>" class="button-primary">
                <?php echo esc_html__('Reset license key') ?>
            </a>
            <?php
        }

        public function upgradePageCallback() {
            if ( !empty($_GET['reset_license_key']) ) {
                $this->setLicenseCode('');
                wp_safe_redirect( esc_url_raw( $this->getUpgradeUrl() ) );
            }

            ?>
            <div class="wrap">
                <h2 class="nav-tab-wrapper">
                    <?php echo esc_html__('Update') ?> <?php echo esc_html__($this->pluginTitle); ?>
                </h2>
                <br/>
                <?php if ( empty($this->getLicenseCode()) ): ?>
                    <?php $this->settingsForm(); ?>
                <?php else: ?>
                    <?php $this->requestUpgradeSection(); ?>
                <?php endif; ?>
            </div>
            <?php
        }

        private function settingsForm() {
            if ( !empty($_POST) ) {
                $keyCode = sanitize_text_field($_POST['license_key']);
                $this->setLicenseCode( $keyCode );

                wp_safe_redirect( esc_url_raw( $this->getUpgradeUrl() ) );
            }

            ?>
            <form method="POST">
                <label>
                    <?php echo esc_html__('Please inform your license key:') ?>
                </label>
                <br/>
                <input type="text" size="30" name="license_key" required>
                <br/>
                <br/>
                <input name="save"
                        value="<?php echo esc_html__('Save settings') ?>"
                        class="button-primary"
                        type="submit">
                <?php $this->btBackPlugins(); ?>
            </form>
            <script>
                (function($){
                    jQuery(document).ready(function($){
                        $('input[name="license_key"]').focus();
                    });
                })(jQuery);
            </script>
            <?php
        }

        private function requestUpgradeSection() {
            ?>
            <h4>
                <?php echo esc_html__('License code:') . ' ' . $this->getLicenseCode(); ?>
            </h4>
            <h3>
                <?php echo esc_html__('Checking for new updates from remote server...'); ?>
            </h3>
            <?php

            try {
                $force = !empty($_GET['force_update']);
                $package = $this->requestRemoteUpdate($force);
                
                $this->getRemotePackage($package);
                $this->setUpdateError('');
                $this->setHasUpdate(false);

                $text = esc_html__('Plugin updated sucessfully to the new version!');
                $text = sprintf('<h3 style="color: green;">%s</h3>', $text);
                $text = wp_kses($text, array('h3' => array('style' => array())));

                echo $text;
            }
            catch (MHAlreadyUpgradedException $e) {
                $this->setUpdateError('');

                $text = $e->getMessage();
                $text = sprintf('<h3 style="color: green;">%s</h3>', $text);
                $text = wp_kses($text, array('h3' => array('style' => array()), 'a' => array('href' => array())));

                echo $text;
            }
            catch (Exception $e) {
                $this->setUpdateError(esc_html__($e->getMessage()));

                $text = $e->getMessage();
                $text = sprintf('<h3 style="color: red;">%s</h3>', $text);
                $text = wp_kses($text, array('h3' => array('style' => array()), 'a' => array('href' => array(), 'target' => array())));

                echo $text;
            }

            ?>
            <?php $this->btResetLicense(); ?>
            <?php $this->btBackPlugins(); ?>
            <?php
        }
    }
}

if ( !class_exists('MHAlreadyUpgradedException') ) {
    class MHAlreadyUpgradedException extends Exception {
    }
}