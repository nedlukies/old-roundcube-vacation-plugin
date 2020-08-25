<?php
/**
 * Virtual/SQL driver
 *
 * @package	plugins
 * @uses	rcube_plugin
 * @author	Jasper Slits <jaspersl at gmail dot com>
 * @version	1.9
 * @license     GPL
 * @link	https://sourceforge.net/projects/rcubevacation/
 * @todo	See README.TXT
 */

class Virtual extends VacationDriver
{

    private $db, $domain, $domain_id, $goto = "";
    private $db_user;

    private $mailbox = null;


    public function init()
    {
        // Use the DSN from db.inc.php or a dedicated DSN defined in config.ini

        if (empty($this->cfg['dsn'])) {
            $this->db = $this->rcmail->db;
            $dsn = MDB2::parseDSN($this->rcmail->config->get('db_dsnw'));
        } else {
            $this->db = new rcube_db($this->cfg['dsn'], '', false);
            $this->db->db_connect('w');

            $this->db->set_debug((bool)$this->rcmail->config->get('sql_debug'));
            $dsn = MDB2::parseDSN($this->cfg['dsn']);
            $this->db->set_debug(true);

        }
        // Save username for error handling
        $this->db_user = $dsn['username'];

        if (isset($this->cfg['createvacationconf']) && $this->cfg['createvacationconf']) {

            $this->createVirtualConfig($dsn);
        }
    }

    /**
     * @return Array Values for the form
     */
    public function _get()
    {
        ini_set('display_errors','on');
        $vacArr = array("subject" => "", "body" => "", "activefrom" => "", "activeuntil" => "");
        //   print_r($vacArr);
        $fwdArr = $this->virtual_alias();

        if (!$this->mailbox) $this->getMailbox();
        $sql = sprintf(
            "SELECT subject,body,activefrom,activeuntil,active FROM vacation WHERE email='%s'",
            rcube::Q($this->mailbox['username'])
        );

        echo $sql;

        $res = $this->db->query($sql);
        if ($error = $this->db->is_error()) {
            rcube::raise_error(array(
                'code' => 601, 'type' => 'db', 'file' => __FILE__,
                'message' => "Vacation plugin: query on {$this->cfg['dbase']}.vacation failed. Check DSN and verify that SELECT privileges on {$this->cfg['dbase']}.vacation are granted to user '{$this->db_user}'. <br/><br/>Error message:  " . $error
            ), true, true);
        }



        if ($row = $this->db->fetch_assoc($res)) {
            $vacArr['body'] = $row['body'];
            $vacArr['subject'] = $row['subject'];
            $vacArr['activefrom'] = $row['activefrom'];
            $vacArr['activeuntil'] = $row['activeuntil'];
            //$vacArr['enabled'] = ($row['active'] == 1) && ($fwdArr['enabled'] == 1);
            $vacArr['enabled'] = $row['active'];
        }

        return array_merge($fwdArr, $vacArr);
    }

    public function getMailbox() {
        $res = $this->db->query(sprintf("SELECT * FROM mailbox where username='%s'", $this->user->get_username()));
        $this->mailbox = $this->db->fetch_assoc($res);
        $this->domain = $this->mailbox['domain'];
        return $this->mailbox;
    }

    /**
     * @return boolean True on succes, false on failure
     */
    public function setVacation()
    {
        // If there is an existing entry in the vacation table, delete it.
        // This also triggers the cascading delete on the vacation_notification, but's ok for now.

        // We store since version 1.6 all data into one row.
        $aliasArr = array();

        // Sets class property
        $this->domain_id = $this->domainLookup();



        $sql = sprintf("UPDATE vacation SET modified=now(),active=FALSE WHERE email='%s'", rcube::Q($this->mailbox['username']));


        $this->db->query($sql);

        $update = ($this->db->affected_rows() == 1);

        // Delete the alias for the vacation transport (Postfix)
        $sql = $this->translate($this->cfg['delete_query']);

        $this->db->query($sql);
        if ($error = $this->db->is_error()) {
            if (strpos($error, "no such field")) {
                $error = " Configure either domain_lookup_query or use %d in config.ini's delete_query rather than %i. <br/><br/>";
            }

            rcube::raise_error(array(
                'code' => 601, 'type' => 'db', 'file' => __FILE__,
                'message' => "Vacation plugin: Error while saving records to {$this->cfg['dbase']}.vacation table. <br/><br/>" . $error
            ), true, true);

        }


        // Save vacation message in any case

	      // LIMIT date arbitrarily put to next century (vacation.pl doesn't like NULL value)
        if (!$update) {
            $sql = "INSERT INTO {$this->cfg['dbase']}.vacation " .
                "( subject, body, domain, cache, active, created, activefrom, activeuntil, email ) " .
                "VALUES ( ?, ?, ?, '', ?, NOW(), ?, ?, ? )";
        } else {
            $sql = "UPDATE {$this->cfg['dbase']}.vacation SET modified=now(),subject=?,body=?,domain=?,active=?, activefrom=?, activeuntil=? WHERE email=?";
        }

        $this->db->query(
            $sql,
            $this->subject,
            $this->body,
            $this->domain,
            $this->enable,
            $this->activefrom,
            $this->activeuntil,
            rcube::Q($this->mailbox['username'])
        );
        if ($error = $this->db->is_error()) {
            if (strpos($error, "no such field")) {
                $error = " Configure either domain_lookup_query or use \%d in config.ini's insert_query rather than \%i<br/><br/>";
            }

            rcube::raise_error(
                array(
                    'code' => 601, 'type' => 'db', 'file' => __FILE__,
                    'message' => "Vacation plugin: Error while saving records to {$this->cfg['dbase']}.vacation table. <br/><br/>" . $error
                ), true, true
            );
        }

        // (Re)enable the vacation transport alias
        if ($this->enable && $this->body != "" && $this->subject != "") {
            $aliasArr[] = '%g';
        }

        $aliasArr[] = '%e';

        // Set a forward
        if ($this->enable && $this->forward != null) {
            $aliasArr[] = '%f';
        }

        // Aliases are re-created if $sqlArr is not empty.
        $sql = $this->translate($this->cfg['delete_query']);
        $this->db->query($sql);

        // One row to store all aliases
        if (!empty($aliasArr)) {

            $alias = join(",", $aliasArr);
            $sql = str_replace('%g', $alias, $this->cfg['insert_query']);
            $sql = $this->translate($sql);

            $this->db->query($sql);
            if ($error = $this->db->is_error()) {
                rcube::raise_error(array(
                    'code' => 601, 'type' => 'db', 'file' => __FILE__,
                    'message' => "Vacation plugin: Error while executing {$this->cfg['insert_query']} <br/><br/>" . $error
                ), true, true);
            }
        }
        return true;
    }

    /**
     * @return string SQL query with substituted parameters
     */
    private function translate($query)
    {
	// vacation.pl assume that people won't use # as a valid mailbox character
        return str_replace(
            array('%e', '%d', '%i', '%g', '%f', '%m'),
            array(
                $this->mailbox['username'], $this->domain, $this->domain_id,
                rcube::Q(str_replace('@', '#', $this->mailbox['username'])) . "@" . $this->cfg['transport'], $this->forward, $this->cfg['dbase']
            ),
            $query
        );
    }

// Sets %i. Lookup the domain_id based on the domainname. Returns the domainname if the query is empty
    private function domainLookup()
    {

        if (!$this->mailbox) $this->getMailbox();
        // Sets the domain
        list($username, $this->domain) = explode('@',$this->mailbox['username']);
        if (!empty($this->cfg['domain_lookup_query'])) {
            $res = $this->db->query($this->translate($this->cfg['domain_lookup_query']));

            if (!$row = $this->db->fetch_array($res)) {
                rcube::raise_error(array(
                    'code' => 601, 'type' => 'db', 'file' => __FILE__,
                    'message' => "Vacation plugin: domain_lookup_query did not return any row. Check config.ini <br/><br/>" . $this->db->is_error()
                ), true, true);

            }
            return $row[0];
        } else {
            return $this->domain;
        }
    }

    /**
     * Creates configuration file for vacation.pl
     *
     * @param array $dsn dsn
     *
     * @return void
     */
    private function createVirtualConfig(array $dsn)
    {

        $virtual_config = "/etc/mail/postfixadmin/";
        if (!is_writeable($virtual_config)) {
            rcube::raise_error(array(
                'code' => 601, 'type' => 'php', 'file' => __FILE__,
                'message' => "Vacation plugin: Cannot create {$virtual_config}vacation.conf . Check permissions.<br/><br/>"
            ), true, true);
        }

        // Fix for vacation.pl
        if ($dsn['phptype'] == 'pgsql') {
            $dsn['phptype'] = 'Pg';
        }

        $virtual_config .= "vacation.conf";
        // Only recreate vacation.conf if config.ini has been modified since
        if (!file_exists($virtual_config) || (filemtime("plugins/vacation/config.ini") > filemtime($virtual_config))) {
            $config = sprintf("
        \$db_type = '%s';
        \$db_username = '%s';
        \$db_password = '%s';
        \$db_name     = '%s';
        \$vacation_domain = '%s';", $dsn['phptype'], $dsn['username'], $dsn['password'], $this->cfg['dbase'], $this->cfg['transport']);
            file_put_contents($virtual_config, $config);
        }
    }

    /**
     * Retrieves the localcopy and/or forward settings.
     *
     * @return array with virtual aliases
     */
    private function virtual_alias()
    {
        $forward = "";
        $enabled = false;
        // vacation.pl assume that people won't use # as a valid mailbox character
        if (!$this->mailbox) $this->getMailbox();
        $goto = rcube::Q(str_replace('@', '#', $this->mailbox['username'])) . "@" . $this->cfg['transport'];

        // Backwards compatiblity. Since >=1.6 this is no longer needed
        $sql = str_replace("='%g'", "<>''", $this->cfg['select_query']);

        $res = $this->db->query($this->translate($sql));

        $rows = array();

        while (list($row) = $this->db->fetch_array($res)) {

            // Postfix accepts multiple aliases on 1 row as well as an alias per row
            if (strpos($row, ",") !== false) {
                $rows = explode(",", $row);
            } else {
                $rows[] = $row;
            }
        }



        foreach ($rows as $row) {
            // Source = destination means keep a local copy
            if ($row == $this->mailbox['username']) {
                $keepcopy = true;
            } else {
                // Neither keepcopy or postfix transport means it's an a forward address
                if ($row == $goto) {
                    $enabled = true;
                } else {
                    // Support multi forwarding addresses
                    $forward .= $row . ",";
                }
            }

        }
        // Substr removes any trailing comma
        return array("forward" => substr($forward, 0, -1), "keepcopy" => $keepcopy, "enabled" => $enabled);
    }

    /**
     * Destroy the database connection of our temporary database connection
     */
    public function __destruct()
    {
        if (!empty($this->cfg['dsn']) && is_resource($this->db)) {
            $this->db = null;
        }
    }
}

?>
