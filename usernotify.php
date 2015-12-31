<?php
/*
* @package    User Notify System Plugin
* @copyright  (C) 2015 RJCreations. All rights reserved.
* @license    GNU General Public License version 3 or later; see LICENSE.txt
*/

defined('_JEXEC') or die;

class PlgSystemUsernotify extends JPlugin
{
	protected $cparms = null;	// com_usernotify params
	protected $targs = null;	// targeted components/extensions

	public function __construct (&$subject, $config)
	{
		parent::__construct($subject, $config);
		if (JComponentHelper::isInstalled('com_usernotify')) {
			$this->cparms = JComponentHelper::getParams('com_usernotify');
			$this->targs = $this->cparms->get('target',array());
			if (JDEBUG) { JLog::addLogger(array('text_file'=>'com_usernotify.log.php'), JLog::ALL, array('com_usernotify')); }
		}
	}


	public function onContentBeforeSave ($context, $article, $isNew)
	{
		if (!$this->cparms) return true;

		$ctxp = explode('.', $context);
		if (!in_array($ctxp[0], $this->targs)) return true;

		$this->ldump(array('BS::','context'=>$context,'title'=>$article->title,'state'=>$article->state,'publish_up'=>$article->publish_up,'isNew'=>$isNew));
		return true;
	}


	public function onContentAfterSave ($context, $article, $isNew)
	{
		if (!$this->cparms) return true;

		$ctxp = explode('.', $context);
		if (!in_array($ctxp[0], $this->targs)) return true;

		$db = JFactory::getDbo();
		$query = $db->getQuery(true)
			->select('*')
			->from($db->quoteName('#__usernotify_c'))
			->where($db->quoteName('cid') . ' = ' . $article->catid);
		$db->setQuery($query);
		$ccfg = $db->loadAssoc();

		$this->ldump(array('AS::','context'=>$context,'catid'=>$article->catid,'title'=>$article->title,'state'=>$article->state,'publish_up'=>$article->publish_up,'isNew'=>$isNew,'ccfg'=>$ccfg));

		if (!$ccfg) return true;

		ob_start();
		require_once 'helper.php';
		$recips = PlgUserNotifyHelper::getRecipients($article->catid);
		$msg = PlgUserNotifyHelper::getComposedEmail($this->cparms, $ccfg, $article);
		foreach ($recips as $recip) {
			$this->sendNotice($recip['alt_email'] ?: $recip['email'], $this->cparms->get('subject', 'Notification'), $msg);
		}
		$this->ldump(array('OB::'=>ob_get_contents()));
		ob_end_clean();
		return true;
	}


	public function onContentChangeState ($context, $pks, $value)
	{
		if (!$this->cparms) return true;

		$ctxp = explode('.', $context);
		if (!in_array($ctxp[0], $this->targs)) return true;

		$this->ldump(array('CS::','context'=>$context,'pks'=>$pks,'val'=>$value));	//return true;

		$db = JFactory::getDbo();
		$query = $db->getQuery(true)
			->select($db->quoteName('title'))
			->select($db->quoteName('state'))
			->select($db->quoteName('catid'))
			->select($db->quoteName('publish_up'))
			->from($db->quoteName('#__content'))
			->where($db->quoteName('id') . ' IN (' . $pksImploded = implode(',', $pks) . ')');
		$db->setQuery($query);
		$lst = $db->loadAssocList();
		foreach ($lst as $a) {
			// new= state is 1 and publish_up is 0000
			$this->ldump($a);
		}
		return true;
	}


	// clear some db items when a user is deleted
	public function onUserAfterDelete ($user, $success, $msg)
	{
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


	private function sendNotice ($addr, $subj, $body)
	{
		$mailer = JFactory::getMailer();
		$mailer->addRecipient($addr);
		$mailer->setSubject($subj);
		$mailer->isHTML(true);
	//	$mailer->Encoding = 'base64';
		$mailer->setBody('<!DOCTYPE html><html><head></head><body>'.$body.'</body></html>');
		$mailer->AddEmbeddedImage( JPATH_ROOT.'/images/rjcreationslogo.png', 'logo_id', 'rjcreationslogo.png', 'base64', 'image/png' );
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