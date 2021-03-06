<?php
/**
 * @package   AkeebaLoginGuard
 * @copyright Copyright (c)2016-2018 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\LoginGuard\Site\Model;

use Akeeba\LoginGuard\Site\Helper\Tfa;
use AkeebaGeoipProvider;
use DateInterval;
use DateTimeZone;
use Exception;
use FOF30\Model\Model;
use FOF30\Utils\Ip;
use JBrowser;
use JLoader;
use Joomla\CMS\User\User;
use JText;
use JUser;
use RuntimeException;
use stdClass;

// Protect from unauthorized access
defined('_JEXEC') or die();

/**
 * Two Step Verification methods list page's model
 *
 * @since       2.0.0
 */
class Methods extends Model
{
	/**
	 * Returns a list of all available and their currently active records for given user.
	 *
	 * @param   JUser|User  $user  The user object. Skip to use the current user.
	 *
	 * @return  array
	 * @since   2.0.0
	 */
	public function getMethods($user = null)
	{
		if (!is_object($user) || !($user instanceof JUser))
		{
			$user = $this->container->platform->getUser();
		}

		if ($user->guest)
		{
			return array();
		}

		// Get an associative array of TFA methods
		$rawMethods = Tfa::getTfaMethods();
		$methods    = array();

		foreach ($rawMethods as $method)
		{
			$method['active'] = array();
			$methods[$method['name']] = $method;
		}

		// Put the user TFA records into the methods array
		$userTfaRecords = Tfa::getUserTfaRecords($user->id);

		if (!empty($userTfaRecords))
		{
			foreach ($userTfaRecords as $record)
			{
				if (!isset($methods[$record->method]))
				{
					continue;
				}

				$methods[$record->method]['active'][$record->id] = $record;
			}
		}

		return $methods;
	}

	/**
	 * Delete all Two Step Verification methods for the given user.
	 *
	 * @param   JUser|User  $user  The user object to reset TSV for. Null to use the current user.
	 *
	 * @return  void
	 *
	 * @throws  RuntimeException  When the user is invalid or a database error has occurred.
	 * @since   2.0.0
	 */
	public function deleteAll($user = null)
	{
		// Make sure we have a user object
		if (is_null($user))
		{
			$user = $this->container->platform->getUser();
		}

		// If the user object is a guest (who can't have TSV) we abort with an error
		if ($user->guest)
		{
			throw new RuntimeException(JText::_('JERROR_ALERTNOAUTHOR'), 403);
		}

		$db = $this->container->db;
		$query = $db->getQuery(true)
			->delete($db->qn('#__loginguard_tfa'))
			->where($db->qn('user_id') . ' = ' . $db->q($user->id));
		$db->setQuery($query)->execute();
	}

	/**
	 * Format a relative timestamp. It deals with timestamps today and yesterday in a special manner. Example returns:
	 * Yesterday, 13:12
	 * Today, 08:33
	 * January 1, 2015
	 *
	 * @param   string  $dateTimeText  The database time string to use, e.g. "2017-01-13 13:25:36"
	 *
	 * @return  string  The formatted, human-readable date
	 */
	public function formatRelative($dateTimeText)
	{
		// The timestamp is given in UTC. Make sure Joomla! parses it as such.
		$utcTimeZone = new DateTimeZone('UTC');
		$jDate       = $this->container->platform->getDate($dateTimeText, $utcTimeZone);
		$unixStamp   = $jDate->toUnix();

		// I'm pretty sure we didn't have TFA in Joomla back in 1970 ;)
		if ($unixStamp < 0)
		{
			return '&ndash;';
		}

		// I need to display the date in the user's local timezone. That's how you do it.
		$user   = $this->container->platform->getUser();
		$userTZ = $user->getParam('timezone', 'UTC');
		$tz     = new DateTimeZone($userTZ);
		$jDate->setTimezone($tz);

		// Default format string: way in the past, the time of the day is not important
		$formatString    = JText::_('COM_LOGINGUARD_LBL_DATE_FORMAT_PAST');
		$containerString = JText::_('COM_LOGINGUARD_LBL_PAST');

		// If the timestamp is within the last 72 hours we may need a special format
		if ($unixStamp > (time() - (72 * 3600)))
		{
			// Is this timestamp today?
			$jNow = $this->container->platform->getDate();
			$jNow->setTimezone($tz);
			$checkNow  = $jNow->format('Ymd', true);
			$checkDate = $jDate->format('Ymd', true);

			if ($checkDate == $checkNow)
			{
				$formatString    = JText::_('COM_LOGINGUARD_LBL_DATE_FORMAT_TODAY');
				$containerString = JText::_('COM_LOGINGUARD_LBL_TODAY');
			}
			else
			{
				// Is this timestamp yesterday?
				$jYesterday = clone $jNow;
				$jYesterday->setTime(0, 0, 0);
				$oneSecond = new DateInterval('PT1S');
				$jYesterday->sub($oneSecond);
				$checkYesterday = $jYesterday->format('Ymd', true);

				if ($checkDate == $checkYesterday)
				{
					$formatString    = JText::_('COM_LOGINGUARD_LBL_DATE_FORMAT_YESTERDAY');
					$containerString = JText::_('COM_LOGINGUARD_LBL_YESTERDAY');
				}
			}
		}

		return sprintf($containerString, $jDate->format($formatString, true));
	}

	/**
	 * Extract the the browser and platform from a User Agent string and format them in a human-readable manner.
	 *
	 * @param   string  $ua  A User-Agent string
	 *
	 * @return  string  Human readable format, e.g. "Chrome on Windows"
	 * @since   2.0.0
	 */
	public function formatBrowser($ua)
	{
		if (empty($ua))
		{
			return '';
		}

		JLoader::import('joomla.environment.browser');
		$jBrowser = JBrowser::getInstance($ua);
		$platform = $jBrowser->getPlatform();
		$browser  = $jBrowser->getBrowser();

		// Let's make sure we have the correct platform
		if (strpos($ua, '; Android') !== false)
		{
			$platform = 'android';
		}
		elseif ((strpos($ua, 'iPhone;')) !== false || (strpos($ua, 'iPad;') !== false) || (strpos($ua, 'iPod;') !== false) || (strpos($ua, 'iPad touch;') !== false))
		{
			$platform = 'ios';
		}
		elseif (strpos($ua, 'Linux') !== false)
		{
			$platform = 'linux';
		}

		// Let's make sure we have the correct browser
		if (strpos($ua, 'Edge/'))
		{
			$browser = 'edge';
		}
		if (strpos($ua, 'Chromium/'))
		{
			$browser = 'chromium';
		}
		elseif (strpos($ua, 'Opera Mini/'))
		{
			$browser = 'operamini';
		}
		elseif (strpos($ua, 'Maxthon;'))
		{
			$browser = 'maxthon';
		}
		elseif (strpos($ua, 'YaBrowser/'))
		{
			$browser = 'yandex';
		}
		elseif (strpos($ua, 'Avant Browser'))
		{
			$browser = 'avant';
		}
		elseif (strpos($ua, 'Camino/'))
		{
			$browser = 'camino';
		}
		elseif (strpos($ua, 'Epiphany/'))
		{
			$browser = 'epiphany';
		}
		elseif (strpos($ua, 'Galeon/'))
		{
			$browser = 'galeon';
		}
		elseif (strpos($ua, 'Iceweasel/'))
		{
			$browser = 'iceweasel';
		}
		elseif (strpos($ua, 'K-Meleon/'))
		{
			$browser = 'kmeleon';
		}
		elseif (strpos($ua, 'Midori/'))
		{
			$browser = 'midori';
		}
		elseif (strpos($ua, 'rekonq/'))
		{
			$browser = 'rekonq';
		}
		elseif (strpos($ua, 'SamsungBrowser/'))
		{
			$browser = 'samsung';
		}
		elseif (strpos($ua, 'SeaMonkey/'))
		{
			$browser = 'seamonkey';
		}
		elseif (strpos($ua, 'Iron/'))
		{
			$browser = 'iron';
		}
		elseif (strpos($ua, 'Dalvik/'))
		{
			$browser = 'android';
		}
		elseif (strpos($ua, 'presto/'))
		{
			$browser = 'android';
		}
		elseif (strpos($ua, 'Vivaldi/'))
		{
			$browser = 'vivaldi';
		}

		// Translate the information
		$platformText  = JText::_('COM_LOGINGUARD_LBL_BROWSER_PLATFORM_' . $platform);
		$browserString = JText::_('COM_LOGINGUARD_LBL_BROWSER_' . $browser);

		return JText::sprintf('COM_LOGINGUARD_LBL_BROWSER', $browserString, $platformText);
	}

	/**
	 * Format an IP address in a human readable format, either as an IP or - if the Akeeba GeoIP plugin is installed and
	 * enabled - as a country or as a city and country (depending on the GeoIP database you have installed).
	 *
	 * @param   string  $ip  The IPv4/IPv6 address of the visitor.
	 *
	 * @return  string  Human readable format e.g. "on 123.123.123.123", "from Germany", or "from Tokyo, Japan"
	 * @since   2.0.0
	 */
	public function formatIp($ip)
	{
		if (empty($ip))
		{
			return '';
		}

		$string = JText::sprintf('COM_LOGINGUARD_LBL_FROMIP', $ip);

		if (class_exists('AkeebaGeoipProvider'))
		{
			$geoip     = new AkeebaGeoipProvider();
			$country   = $geoip->getCountryName($ip);

			if (!empty($country))
			{
				$string = JText::sprintf('COM_LOGINGUARD_LBL_FROMCOUNTRY', $country);
			}

			if (method_exists($geoip, 'getCity'))
			{
				$city = $geoip->getCity($ip);

				if (!empty($city) && !empty($country))
				{
					$string = JText::sprintf('COM_LOGINGUARD_LBL_FROMCITYCOUNTRY', $city, $country);
				}
			}
		}

		return $string;
	}

	/**
	 * Set the user's "don't show this again" flag.
	 *
	 * @param   JUser  $user  The user to check
	 * @param   bool   $flag  True to set the flag, false to unset it (it will be set to 0, actually)
	 *
	 * @return  void
	 * @since   2.0.0
	 */
	public function setFlag(JUser $user, $flag = true)
	{
		$db = $this->container->db;
		$query = $db->getQuery(true)
			->select($db->qn('profile_value'))
			->from($db->qn('#__user_profiles'))
			->where($db->qn('user_id') . ' = ' . $db->q($user->id))
			->where($db->qn('profile_key') . ' = ' . $db->q('loginguard.dontshow'));

		try
		{
			$result = $db->setQuery($query)->loadResult();
		}
		catch (Exception $e)
		{
			return;
		}

		$exists = !is_null($result);

		$object = (object) array(
			'user_id'       => $user->id,
			'profile_key'   => 'loginguard.dontshow',
			'profile_value' => ($flag ? 1 : 0),
			'ordering'      => 1
		);

		if (!$exists)
		{
			$db->insertObject('#__user_profiles', $object);
		}
		else
		{
			$db->updateObject('#__user_profiles', $object, array('user_id', 'profile_key'));
		}
	}
}
