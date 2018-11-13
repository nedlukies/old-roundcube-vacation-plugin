<?php
/**
 * VacationFactory class
 * 
 * PHP version 7
 *
 * @category Class
 * @package  Plugins
 * @uses     rcube_plugin
 * @author   Jasper Slits <jaspersl@gmail.com>
 * @license  http://opensource.org/licenses/gpl-3.0 GNU/GPLv3
 * @link     https://github.com/yuusou/roundcube-vacation-plugin
 * @todo     See README.TXT
 */

/**
 * VacationFactory class
 *
 * @category Class
 * @package  Plugins
 * @uses     rcube_plugin
 * @author   Jasper Slits <jaspersl@gmail.com>
 * @author   Roman Plessl <roman@plessl.info>
 * @author   Andre Oliveira <me@andreoliveira.io>
 * @license  http://opensource.org/licenses/gpl-3.0 GNU/GPLv3
 * @version  Release: 2.1
 * @link     https://github.com/yuusou/roundcube-vacation-plugin
 * @todo     See README.TXT
 */
class VacationDriverFactory
{

    /**
     * Class constructor
     */
    public function __construct()
    {
        die("Cannot instantiate this class");
    }

    /**
     * Create driver.
     * 
     * @param string $driver class to be loaded
     * 
     * @return object specific driver 
     */
    public static function create(string $driver)
    {
        $driver = strtolower($driver);
        $driverClass = sprintf("plugins/vacation/lib/%s.class.php", $driver);

        if (!is_readable($driverClass)) {
            rcube::raise_error(
                array(
                    'code' => 601, 'type' => 'php', 'file' => __FILE__,
                    'message' => sprintf(
                        "Vacation plugin: Driver '%s' cannot be loaded using %s", 
                        $driver, $driverClass
                    )
                ), true, true
            );
        }

        include $driverClass;
        return new $driver;
    }
} ?>
