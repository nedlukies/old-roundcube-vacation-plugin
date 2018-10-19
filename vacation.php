<?php
/*
 * Vacation plugin that adds a new tab to the settings section
 * to enable forward / out of office replies.
 *
 * @package	plugins
 * @uses	rcube_plugin
 * @author	Jasper Slits <jaspersl@gmail.com>
 * @version	1.9
 * @license     GPL
 * @link	https://sourceforge.net/projects/rcubevacation/
 * @todo	See README.TXT
*/

// Load required dependencies
require 'lib/vacationdriver.class.php';
require 'lib/dotforward.class.php';
require 'lib/vacationfactory.class.php';
require 'lib/VacationConfig.class.php';

class vacation extends rcube_plugin {

    public $task = 'settings';
    private $v = "";
    private $inicfg = "";
    private $enableVacationTab = true;
    private $vcObject;

    public function init() {
        $this->add_texts('localization/', array('vacation'));
        $this->load_config();
        
        $this->inicfg = $this->readIniConfig();

        

        // Don't proceed if the current host does not support vacation
        if (!$this->enableVacationTab) {
            return false;
        }

        $this->v = VacationDriverFactory::Create($this->inicfg['driver']);

        $this->v->setIniConfig($this->inicfg);
        $this->register_action('plugin.vacation', array($this, 'vacation_init'));
        $this->register_action('plugin.vacation-save', array($this, 'vacation_save'));
        $this->register_handler('plugin.vacation_form', array($this, 'vacation_form'));
        // The vacation_aliases method is defined in vacationdriver.class.php so use $this->v here
        $this->register_action('plugin.vacation_aliases', array($this->v, 'vacation_aliases'));
        $this->include_script('https://cdn.jsdelivr.net/npm/flatpickr@latest/dist/flatpickr.js');
        $this->include_script('https://cdn.jsdelivr.net/npm/flatpickr@latest/dist/plugins/rangePlugin.js');
        $this->include_script('vacation.js');
        $this->include_stylesheet('https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css');
        $this->include_stylesheet('skins/default/vacation.css');
        $this->rcmail = rcmail::get_instance();
        $this->user = $this->rcmail->user;
        $this->identity = $this->user->get_identity();
        
        // forward settings are shared by ftp,sshftp and setuid driver.
        $this->v->setDotForwardConfig($this->inicfg['driver'],$this->vcObject->getDotForwardCfg());
    }
    
    public function vacation_init() {
        $this->add_texts('localization/', array('vacation'));
        $rcmail = rcmail::get_instance();
        $rcmail->output->set_pagetitle($this->gettext('autoresponder'));
        //Load template
        $rcmail->output->send('vacation.vacation');
    }
    
    public function vacation_save() {
        $rcmail = rcmail::get_instance();

        // Initialize the driver
        $this->v->init();

        if ($this->v->save()) {
//          $this->v->getActionText() Dummy for now
            $rcmail->output->show_message($this->gettext("success_changed"), 'confirmation');
        } else {
            $rcmail->output->show_message($this->gettext("failed"), 'error');
        }
        $this->vacation_init();
    }

    // Parse config.ini and get configuration for current host
    private function readIniConfig() {
        $this->vcObject = new VacationConfig();
        $this->vcObject->setCurrentHost($_SESSION['imap_host']);
        $config = $this->vcObject->getCurrentConfig();

        if (false !== ($errorStr = $this->vcObject->hasError())) {
            rcube::raise_error(array('code' => 601, 'type' => 'php', 'file' => __FILE__,
                        'message' => sprintf("Vacation plugin: %s", $errorStr)), true, true);
        }
        $this->enableVacationTab = $this->vcObject->hasVacationEnabled();

        return $config;
    }
    
    public function vacation_form() {
        $rcmail = rcmail::get_instance();
        // Initialize the driver
        $this->v->init();
        $settings = $this->v->_get();

        // Load default body & subject if present.
        if (empty($settings['subject']) && $defaults = $this->v->loadDefaults()) {
            $settings['subject'] = $defaults['subject'];
            $settings['body'] = $defaults['body'];
        }

        $rcmail->output->set_env('product_name', $rcmail->config->get('product_name'));
        // return the complete edit form as table

        $out = '<style>.uibox{overflow-y:scroll;}</style>';
        $out .= '<fieldset><legend>' . $this->gettext('outofoffice') . ' ::: ' . $rcmail->user->data['username'] . '</legend>' . "\n";
        $out .= '<table class="propform"><tbody>';
        // show autoresponder properties

        // Auto-reply enabled
        $field_id = 'vacation_enabled';
        $input_autoresponderactive = new html_checkbox(array('name' => '_vacation_enabled', 'id' => $field_id, 'value' => 1));
        $out .= sprintf("<tr><td class=\"title\"><label for=\"%s\">%s</label></td><td>%s</td></tr>\n",
                $field_id,
                rcube_utils::rep_specialchars_output($this->gettext('autoreply')),
                $input_autoresponderactive->show($settings['enabled']));

        // Subject
        $field_id = 'vacation_subject';
        $input_autorespondersubject = new html_inputfield(array('name' => '_vacation_subject', 'id' => $field_id, 'size' => 90));
        $out .= sprintf("<tr><td class=\"title\"><label for=\"%s\">%s</label></td><td>%s</td></tr>\n",
                $field_id,
                rcube_utils::rep_specialchars_output($this->gettext('autoreplysubject')),
                $input_autorespondersubject->show($settings['subject']));

	// Date active from
        $field_id = 'vacation_activefrom';
        $input_autoresponderactivefrom = new html_inputfield(array('name' => '_vacation_activefrom', 'id' => $field_id, 'size' => 45));
        $out .= sprintf("<tr><td class=\"title\"><label for=\"%s\">%s</label></td><td>%s</td></tr>\n",
                $field_id,
                rcube_utils::rep_specialchars_output($this->gettext('activefrom')),
                $input_autoresponderactivefrom->show($settings['activefrom']));

	// Date active until
        $field_id = 'vacation_activeuntil';
        $input_autoresponderactiveuntil = new html_inputfield(array('name' => '_vacation_activeuntil', 'id' => $field_id, 'size' => 45));
        $out .= sprintf("<tr><td class=\"title\"><label for=\"%s\">%s</label></td><td>%s</td></tr>\n",
                $field_id,
                rcube_utils::rep_specialchars_output($this->gettext('activeuntil')),
                $input_autoresponderactiveuntil->show($settings['activeuntil']));

        // Out of office body
        $field_id = 'vacation_body';
        $input_autoresponderbody = new html_textarea(array('name' => '_vacation_body', 'id' => $field_id, 'cols' => 88, 'rows' => 20));
        $out .= sprintf("<tr><td class=\"title\"><label for=\"%s\">%s</label></td><td>%s</td></tr>\n",
                $field_id,
                rcube_utils::rep_specialchars_output($this->gettext('autoreplymessage')),
                $input_autoresponderbody->show($settings['body']));

        /* We only use aliases for .forward and only if it's enabled in the config*/
        if ($this->v->useAliases()) {
            $size = 0;

            // If there are no multiple identities, hide the button and add increase the size of the textfield
            $hasMultipleIdentities = $this->v->vacation_aliases('buttoncheck');
            if ($hasMultipleIdentities == '') $size = 15;

            $field_id = 'vacation_aliases';
            $input_autoresponderalias = new html_inputfield(array('name' => '_vacation_aliases', 'id' => $field_id, 'size' => 75+$size));
            $out .= '<tr><td class=\"title\">' . $this->gettext('separate_alias') . '</td></tr>';

            // Inputfield with button
            $out .= sprintf('<tr><td class=\"title\"><label for="%s">%s</label></td><td>%s', 
                $field_id, rcube_utils::rep_specialchars_output($this->gettext('aliases')),
                $input_autoresponderalias->show($settings['aliases']));
            if ($hasMultipleIdentities!='')
                $out .= sprintf('<input type="button" id="aliaslink" class="button" value="%s"/>',
            rcube_utils::rep_specialchars_output($this->gettext('aliasesbutton')));
            $out .= "</td></tr>";

        }
        $out .= '</tbody></table>'.PHP_EOL.'</fieldset>';

        $out .= '<fieldset><legend>' . $this->gettext('forward') . '</legend>';
        $out .= '<table class="propform"><tbody>';

        // Keep a local copy of the mail
        /*$field_id = 'vacation_keepcopy';
        $input_localcopy = new html_checkbox(array('name' => '_vacation_keepcopy', 'id' => $field_id, 'value' => 1));
        $out .= sprintf("<tr><td class=\"title\"><label for=\"%s\">%s</label></td><td>%s</td></tr>\n",
            $field_id,
            rcube_utils::rep_specialchars_output($this->gettext('keepcopy')),
            $input_localcopy->show($settings['keepcopy'])); */

        // Information on the forward in a seperate fieldset.
        if (! isset($this->inicfg['disable_forward']) || ( isset($this->inicfg['disable_forward']) && $this->inicfg['disable_forward']==false))
        {
            // Forward mail to another account
            $field_id = 'vacation_forward';
            $input_autoresponderforward = new html_inputfield(array('name' => '_vacation_forward', 'id' => $field_id, 'size' => 90));
            $out .= sprintf("<tr><td class=\"title\"><label for=\"%s\">%s</label></td><td>%s<br/>%s</td></tr>\n",
                $field_id,
                rcube_utils::rep_specialchars_output($this->gettext('forwardingaddresses')),
                $input_autoresponderforward->show($settings['forward']),
                $this->gettext('separate_forward'));

        }
        $out .= "</tbody></table></fieldset>\n";

        $rcmail->output->add_gui_object('vacationform', 'vacation-form');
        return $out;
    }
}

?>
