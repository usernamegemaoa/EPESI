<?php
/**
 * @author Arkadiusz Bisaga <abisaga@telaxus.com>
 * @copyright Copyright &copy; 2007, Telaxus LLC
 * @version 1.0
 * @licence SPL
 * @package epesi-tests
 * @subpackage search
 */
defined("_VALID_ACCESS") || die('Direct access forbidden');

class Tests_SearchInstall extends ModuleInstall {
	public static function install() {
		return true;
	}
	
	public static function uninstall() {
		return true;
	}
}

?>
