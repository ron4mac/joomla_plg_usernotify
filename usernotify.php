<?php
/*
* @package    User Notify System Plugin
* @copyright  (C) 2016 RJCreations. All rights reserved.
* @license    GNU General Public License version 3 or later; see LICENSE.txt
*/

defined('_JEXEC') or die;

class PlgSystemUsernotify extends JPlugin
{
	protected $cparms = null;	// com_usernotify params
	protected $targs = null;	// targeted components/extensions
	protected $_db;				// convenience db instance

	public function __construct (&$subject, $config)
	{
		parent::__construct($subject, $config);
		// check for com_usernotify -- nothing to do if it is not installed
		if (JComponentHelper::isInstalled('com_usernotify')) {
			// get the com_usernotify options
			$this->cparms = JComponentHelper::getParams('com_usernotify');
			// extract the components that are being watched
			$this->targs = $this->cparms->get('target',array());
			// get the database
			$this->_db = JFactory::getDbo();
			// setup some logging
			if (JDEBUG) { JLog::addLogger(array('text_file'=>'com_usernotify.log.php'), JLog::ALL, array('com_usernotify')); }
		}
	}


	public function onContentBeforeSave ($context, $article, $isNew)
	{
		// immediately return if nothing to do
		if ($this->ignoreEvent($context)) return true;

		$this->ldump(array('BS::','context'=>$context,'title'=>$article->title,'state'=>$article->state,'publish_up'=>$article->publish_up,'isNew'=>$isNew));
		return true;
	}


	public function onContentAfterSave ($context, $article, $isNew)
	{
		// immediately return if not published or if not from a watched extension
		if (empty($article->state) || ($article->state != 1) || $this->ignoreEvent($context)) return true;

		// get the category's notification configuration
		$ccfg = $this->getCatCfg($article->catid);

		// if not a configured category, no need to continue
		if (!$ccfg) return true;

	//	unset($ccfg['checked_out']);unset($ccfg['checked_out_time']);
		$this->ldump(array('AS::','context'=>$context,'catid'=>$article->catid,'title'=>$article->title,'state'=>$article->state,'created'=>$article->created,'modified'=>$article->modified,'publish_up'=>$article->publish_up,'isNew'=>$isNew,'ccfg'=>$ccfg));

		// determine if it is an updated save (originally published more than a day ago)
		$upd = (time() - strtotime($article->publish_up) > 86400);

		// check that this type notification is enabled in the component
		if (($upd && $this->cparms->get('upd', 0) == 0) || (!$upd && $this->cparms->get('pub', 0) == 0)) return true;

		$this->affectNotifications($ccfg, $article, $upd);
		return true;
	}


	public function onContentChangeState ($context, $pks, $value)
	{
		// immediately return if nothing to do
		if ($value == 0 || $this->ignoreEvent($context)) return true;

		$this->ldump(array('CS::','context'=>$context,'pks'=>$pks,'val'=>$value));	//return true;

		$this->_db->setQuery('SELECT title,state,catid,publish_up FROM #__content WHERE id IN ('.implode(',', $pks).')');
		$lst = $this->_db->loadAssocList();
		foreach ($lst as $a) {
			if ($a['state'] == 0) continue;
			if (time() - strtotime($a['publish_up']) > 10) continue;

			// get the category's notification configuration
			$ccfg = $this->getCatCfg($a['catid']);

			// if not a configured category, move on
			if (!$ccfg) continue;

			$this->affectNotifications($ccfg, (object) $a);

			$this->ldump($a);
		}
		return true;
	}


	// clear some db items when a user is deleted
	public function onUserAfterDelete ($user, $success, $msg)
	{
		// immediately return success if no com_usernotify
		if (!$this->cparms) return $success;

		if ($success) {
			$db = JFactory::getDbo();
			$where = $db->quoteName('uid') . ' = ' . (int)$user['id'];

			$query = $db->getQuery(true)
				->delete($db->quoteName('#__usernotify_u'))
				->where($where);
			$db->setQuery($query)->execute();

			$query->clear()
				->delete($db->quoteName('#__usernotify_s'))
				->where($where);
			$db->setQuery($query)->execute();
		}
		return $success;
	}


	private function getCatCfg ($cid)
	{
		$this->_db->setQuery('SELECT * FROM #__usernotify_c WHERE cid='.$cid);
		$ccfg = $this->_db->loadAssoc();
		return $ccfg;
	}


	private function ignoreEvent ($cntx)
	{
		// ignore if no com_usernotify
		if (!$this->cparms) return true;

		// ignore if the context is not for a watched component
		$ctxp = explode('.', $cntx);
		if (!in_array($ctxp[0], $this->targs)) return true;

		return false;
	}


	private function affectNotifications ($ccfg, $article, $upd=false)
	{
		ob_start();
		require_once 'helper.php';
		$recips = PlgUserNotifyHelper::getRecipients($ccfg, true, $upd);
		$msg = PlgUserNotifyHelper::getComposedEmail($this->cparms, $ccfg, $article, true, $upd);
		foreach ($recips as $recip) {
			$this->sendNotice($recip['alt_email'] ?: $recip['email'], $this->cparms->get('subject', 'Notification'), $msg);
		}
		$this->ldump(array('OB::'=>ob_get_contents()));
		ob_end_clean();
	}


	private function sendNotice ($addr, $subj, $body)
	{
		$mailer = JFactory::getMailer();
		$mailer->XMailer = 'Joomla UserNotify';
	//	$mailer->useSendmail();
		$mailer->addRecipient($addr);
		$mailer->setSubject($subj);
		$mailer->isHTML(true);
	//	$mailer->Encoding = 'base64';
		$mailer->setBody('<!DOCTYPE html><html><head></head><body>'.$body.'</body></html>');
	//	$mailer->AltBody = JMailHelper::cleanText(strip_tags($body));
		$mailer->AltBody = htmlspecialchars_decode(strip_tags($body), ENT_QUOTES);
	//	$mailer->AddEmbeddedImage( JPATH_ROOT.'/images/rjcreationslogo.png', 'logo_id', 'rjcreationslogo.png', 'base64', 'image/png' );
		if (!$mailer->Send()) $this->ldump(array('Mailing failed: ',$mailer->ErrorInfo));
	}


	private function sendAlerts ($evt, $atime)
	{
		$ausrs = explode(',',$evt['alert_user']);

		$toTime = $evt['rec_type'] ? ($evt['t_start'] + $evt['event_length']) : $evt['t_end'];
		$evtTime = $this->formattedDateTime($evt['t_start'], $toTime);
		$lb = "\n"; $lbb = "\n\n";
		if ($evt['alert_meth'] & 1) {	//email
			$surl = $this->config->live_site;
			$body = sprintf(JText::_('COM_USERSCHED_ALERT_BLURB'), $this->config->sitename, date('D j F Y g:ia'), $lb, $surl, $lb);
			$body .= $evtTime . $lbb;
			$body .= $evt['text'];
			$this->sendAlert('email', 'Calendar Alert', $body, $ausrs);
		}
		if ($evt['alert_meth'] & 2) {	//SMS
			$splt = explode($lb,$evt['text'],2);
			$body = $evtTime . $lbb;
			$body .= isset($splt[1]) ? $splt[1] : '';
			$this->sendAlert('sms', 'Calendar Alert -- '.$splt[0], $body, $ausrs);
		}
	}


	private function ldump ($vars)
	{
		$dmp = '';
		foreach ($vars as $n=>$var) {
			$dmp .= $n.' => '.print_r($var, true)."\n";
		}
		file_put_contents('plg_usernotify.dump.log', $dmp."\n", FILE_APPEND);
	}

}