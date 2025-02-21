<?php
if ( !class_exists('MHSupport') ) {
    class MHSupport {
        private $pluginAlias;
        private $pluginTitle;
        private $version;

        public function __construct($pluginAlias, $pluginTitle, $pluginBaseFile = null, $pluginAbbrev = null) {
            $this->pluginAlias = $pluginAlias;
            $this->pluginTitle = $pluginTitle;

            if ( !empty($pluginBaseFile) && !empty($pluginAbbrev) ) {
                add_filter('plugin_action_links_' . plugin_basename($pluginBaseFile), array($this, 'pluginLinks') );
            }

            add_action('admin_menu', array($this, 'registerPage'));
            add_action('admin_title', array($this, 'editPageTitle'));
        }
        
        private function getPluginAlias() {
            return $this->pluginAlias;
        }

        public function editPageTitle() {
            global $current_screen, $title;

            if ( !empty($current_screen->base) && ( $current_screen->base == 'admin_page_support-' . $this->getPluginAlias() ) ) {
                $title = $this->pluginTitle . ' ' . esc_html__('Support');
            }

            return $title;  
        }

        public function pluginLinks($links) {
            $supportLink = $this->getSupportLink();

            return array_merge(array($supportLink), $links);
        }

        public function registerPage() {
            add_submenu_page(
                null,
                esc_html__('Plugin support'),
                esc_html__('Plugin support'),
                'manage_options',
                'support-' . $this->getPluginAlias(),
                array($this, 'supportPageCallback')
            );
        }

        private function getSupportUrl() {
            return admin_url('admin.php?page=support-' . esc_attr($this->getPluginAlias()));
        }

        public function getSupportLink() {
            $link = sprintf('<a href="%s">%s</a>', $this->getSupportUrl(), esc_html__('Get support'));

            $link = wp_kses($link, array(
                'a' => array('href' => array())
            ));

            return $link;
        }

        private function getLicenseCode() {
            $options = get_option('mh_upgrader_' . $this->getPluginAlias(), array());
            return !empty($options['license_key']) ? $options['license_key'] : null;
        }

        private function postSupportMessage($postData) {
            $url = 'http://pluggablesoft.com/premiumserver/send_message.php';

            $params = array_merge($postData, array(
                'plugin' => $this->getPluginAlias(),
                'titleOfPlugin' => $this->pluginTitle,
            ));

            $response = wp_remote_post( $url, array(
                'method' => 'POST',
                'headers' => array(),
                'body' => $params,
            ));

            if ( !is_array( $response ) ) {
                throw new Exception('Unknown response error.');
            }

            $header = $response['headers']; // array of http header lines
            $body = $response['body']; // use the content

            $resp = json_decode($body);

            if ( !is_object($resp) ) {
                throw new Exception('Unknown remote server response.');
            }

            if ( !$resp->success ) {
                throw new Exception($resp->message);
            }

            return true;
        }

        private function fieldVal($name, $default = null) {
            return !empty($_POST[$name]) ? $_POST[$name] : $default;
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
            <a href="<?php echo $url; ?>" class="button-primary">
                <?php echo esc_html__('Reset license key') ?>
            </a>
            <?php
        }

        public function supportPageCallback() {
            if ( !empty($_POST) ) {
                try {
                    $this->postSupportMessage($_POST);

                    $msg = esc_html__('Your message was sent successfully to plugin staff.');
                    $pluginsUrl = admin_url('plugins.php');

                    echo '<script>';
                    echo "alert('{$msg}');";
                    echo "window.location.href = '{$pluginsUrl}';";
                    echo '</script>';
                }
                catch (Exception $e) {
                    $text = sprintf('<h3 style="color: red;">%s</h3>', esc_html__($e->getMessage()));
                    $text = wp_kses($text, array('h3' => array('style' => array())));

                    echo $text;
                }
            }

            ?>
            <div class="wrap">
                <h2 class="nav-tab-wrapper">
                    <?php echo esc_html__($this->pluginTitle) . ' ' . esc_html__('Support'); ?>
                </h2>
                <br/>
                <?php $this->settingsForm(); ?>
            </div>
            <?php
        }

        private function settingsForm() {
            ?>
            <form method="POST">
                <label>
                    <?php echo esc_html__('Your contact e-mail:') ?>
                </label>
                <br/>
                <input type="text"
                        size="30"
                        name="email"
                        value="<?php echo $this->fieldVal('email', get_option('admin_email')); ?>"
                        required>
                <br/>
                <br/>
                <label>
                    <?php echo esc_html__('Your license key:') ?>
                </label>
                <br/>
                <input type="text"
                        size="30"
                        name="license"
                        value="<?php echo $this->fieldVal('license', $this->getLicenseCode()); ?>"
                        required>
                <br/>
                <br/>
                <label>
                    <?php echo esc_html__('Your site URL (optional):') ?>
                </label>
                <br/>
                <input type="text"
                        size="30"
                        name="site_url"
                        value="<?php echo $this->fieldVal('site_url', get_site_url()); ?>">
                <br/>
                <br/>
                <label>
                    <?php echo esc_html__('WP-admin login credentials (optional):') ?>
                </label>
                <br/>
                <input type="text"
                        size="30"
                        name="site_login"
                        value="<?php echo $this->fieldVal('site_login', 'admin / password'); ?>">
                <br/>
                <br/>
                <label>
                    <?php echo esc_html__('Message:') ?>
                </label>
                <br/>
                <textarea name="message"
                          rows="8"
                          cols="50"
                          required><?php echo $this->fieldVal('message'); ?></textarea>
                <br/>
                <br/>
                <input name="save"
                        value="<?php echo esc_html__('Send message') ?>"
                        class="button-primary"
                        type="submit">
                <?php $this->btBackPlugins(); ?>
            </form>
            <?php
        }
    }
}
