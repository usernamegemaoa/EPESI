<?php
defined("_VALID_ACCESS") || die('Direct access forbidden');

class Utils_Calendar extends Module {
	private $lang;
	private static $views = array('Agenda','Day','Week','Month','Year');
	private $settings = array('first_day_of_week'=>0,
				  'default_view'=>'Agenda',
				  'custom_rows'=>null,
				  'custom_agenda_cols'=>null,
				  'timeline'=>true,
				  'views'=>null,
				  'start_day'=>'8:00',
				  'end_day'=>'17:00',
				  'interval'=>'1:00',
				  'default_date'=>null);
	private $date; //current date
	private $event_module;
	private $tb;
	private $displayed_events = array();

	public function construct($ev_mod, array $settings=null) {
		$this->lang = $this->init_module('Base/Lang');
		$this->settings = array_merge($this->settings,$settings);

		$this->event_module = str_replace('/','_',$ev_mod);
		if(ModuleManager::is_installed($this->event_module)==-1)
			trigger_error('Invalid event module: '.$this->event_module, E_USER_ERROR);
		$this->set_module_variable('event_module',$this->event_module);

		//default views
		if($this->settings['views']===null) $this->settings['views'] = & self::$views;

		//default date
		if($this->settings['default_date']===null) $this->settings['default_date']=time();

		if(!is_array($this->settings['custom_rows']))
			$this->settings['custom_rows'] = array('timeless'=>$this->lang->t('Timeless'));

		$this->date = & $this->get_module_variable('date',$this->settings['default_date']);


		if($this->isset_unique_href_variable('date'))
			$this->set_date($this->get_unique_href_variable('date'));
		if($this->isset_unique_href_variable('week_date'))
			$this->set_week_date($this->get_unique_href_variable('week_date'));
		if($this->isset_unique_href_variable('shift_week_day'))
			$this->shift_week_day($this->get_unique_href_variable('shift_week_day'));


		if(count($this->settings['views'])>1) {
			$this->tb = $this->init_module('Utils/TabbedBrowser');

			foreach($this->settings['views'] as $k=>$v) {
				if(!in_array($v,self::$views))
					trigger_error('Invalid view: '.$v,E_USER_ERROR);

				$this->tb->set_tab($this->lang->t($v),array($this, strtolower($v)));
				if(strcasecmp($v,$this->settings['default_view'])==0)
					$def_tab = $k;
			}
			if (isset($def_tab)) $this->tb->set_default_tab($def_tab);
		}

		if($this->isset_unique_href_variable('action')) {
			switch($this->get_unique_href_variable('action')) {
				case 'add':
					$this->push_event_action('add',array($this->get_unique_href_variable('time'),($this->get_unique_href_variable('timeless')=='0')?false:$this->get_unique_href_variable('timeless')));
					return;
				case 'switch':
					$views = array_flip($this->settings['views']);
					$view = $this->get_unique_href_variable('tab');
					if (isset($views[$view])) $this->tb->switch_tab($views[$view]);
						else break;
					$this->date = $this->get_unique_href_variable('time');
					break;
			}
		} elseif(isset($_REQUEST['UCaction']) && isset($_REQUEST['UCev_id'])) {
			switch($_REQUEST['UCaction']) {
				case 'delete':
					$this->delete_event($_REQUEST['UCev_id']);
					break;
				case 'move':
					$this->move_event($_REQUEST['UCev_id'],$_REQUEST['UCdate']);
					break;
				case 'view':
				case 'edit':
					$this->push_event_action($_REQUEST['UCaction'],array($_REQUEST['UCev_id']));
					return;
			}
		}

	}

	public function get_current_view(){
		return $this->settings['views'][$this->tb->get_tab()];
	}

	public function settings($key,$val) {
		$this->settings[$key] = $val;
	}

	public function get_date() {
		return $this->date;
	}

	public function get_view() {
		if(isset($this->tb))
			return $this->settings['views'][$this->tb->get_tab()];
		return null;
	}

	public function get_week_start_time() {
		$week_shift = 86400*$this->get_module_variable('week_shift',0);
		$first_day_of_displayed_week = date('w', $this->date)-$this->settings['first_day_of_week'];
		if ($first_day_of_displayed_week<0) $first_day_of_displayed_week += 7;
		$first_day_of_displayed_week *= 86400;
		return strtotime(date('Y-m-d',$this->date+$week_shift-$first_day_of_displayed_week));
	}

	public function get_week_end_time() {
		return $this->get_week_start_time() + 7*86400;
	}

	public function get_day_start_time() {
		return strtotime(date('Y-m-d',$this->date));
	}

	public function get_day_end_time() {
		return $this->get_day_start_time() + 86400;
	}

	public function get_month_start_time() {
		return strtotime(date('Y-m-01',$this->date));
	}

	public function get_month_end_time() {
		return $this->get_day_start_time() + date('t',$this->date)*86400;
	}

	public function get_start_time() {
		switch($this->get_view()) {
			case 'Day':
				return $this->get_day_start_time();
			case 'Week':
				return $this->get_week_start_time();
			case 'Month':
				return $this->get_month_start_time();
			default: return 0;
		}
	}

	public function get_end_time() {
		switch($this->get_view()) {
			case 'Day':
				return $this->get_day_end_time();
			case 'Week':
				return $this->get_week_end_time();
			case 'Month':
				return $this->get_month_end_time();
			default: return 0;
		}
	}

	public function set_date($d) {
		if(!is_numeric($d) && is_string($d)) $d = strtotime($d);
		$this->date = $d;
	}

	/**
	 * Returns timeline array with keys:
	 * - label - human readable start/end time
	 * - time - time shift in seconds between 0:00 and current time, not set for timeless
	 *
	 * @return array
	 */
	private function get_timeline() {
		$timeline = array();

		//timeless
		foreach($this->settings['custom_rows'] as $key=>$label)
			$timeline[] = array('label'=>$label,'time'=>$key);

		if($this->settings['timeline']) {
			//other
			$interval = strtotime($this->settings['interval']);
			$zero_t = strtotime('0:00');
			$start = strtotime($this->settings['start_day']);
			$end = strtotime($this->settings['end_day']);
			if($end===false || $start===false || $interval===false)
				trigger_error('Invalid start/end_day or interval.',E_USER_ERROR);
			$interval -= $zero_t;
			if($end<$start) {
				$curr = $zero_t;
				while($curr<$end) {
					$next = $curr+$interval;
					$timeline[] = array('label'=>Base_RegionalSettingsCommon::time2reg($curr,2,false,false).' - '.Base_RegionalSettingsCommon::time2reg($next,2,false,false),'time'=>($curr-$zero_t),'join_rows'=>1);
					$curr = $next;
				}
				$timeline[] = array('label'=>Base_RegionalSettingsCommon::time2reg($curr,2,false,false).' - '.Base_RegionalSettingsCommon::time2reg($start,2,false,false),'time'=>($curr-$zero_t),'join_rows'=>ceil(($start-$curr)/$interval));
				$day_end = strtotime('23:59')-$interval;
				$curr = $start;
				while($curr<$day_end) {
					$next = $curr+$interval;
					$timeline[] = array('label'=>Base_RegionalSettingsCommon::time2reg($curr,2,false,false).' - '.Base_RegionalSettingsCommon::time2reg($next,2,false,false),'time'=>($curr-$zero_t),'join_rows'=>1);
					$curr = $next;
				}
				$timeline[] = array('label'=>Base_RegionalSettingsCommon::time2reg($curr,2,false,false).' - '.Base_RegionalSettingsCommon::time2reg('23:59',2,false,false),'time'=>($curr-$zero_t));
			} else {
				$timeline[] = array('label'=>Base_RegionalSettingsCommon::time2reg($zero_t,2,false,false).' - '.Base_RegionalSettingsCommon::time2reg($start,2,false,false),'time'=>0,'join_rows'=>ceil(($start-$zero_t)/$interval));
				while($start<$end) {
					$next = $start+$interval;
					$timeline[] = array('label'=>Base_RegionalSettingsCommon::time2reg($start,2,false,false).' - '.Base_RegionalSettingsCommon::time2reg($next,2,false,false),'time'=>($start-$zero_t),'join_rows'=>1);
					$start = $next;
				}
				$timeline[] = array('label'=>Base_RegionalSettingsCommon::time2reg($start,2,false,false).' - '.Base_RegionalSettingsCommon::time2reg('23:59',2,false,false),'time'=>($start-$zero_t));
			}
		}
		return $timeline;
	}

	public function body($arg = null) {
		load_js($this->get_module_dir().'calendar.js');
		$this->js('Utils_Calendar.day_href = \''.Epesi::escapeJS($this->create_unique_href_js(array('action'=>'switch','time'=>'__DATE__', 'tab'=>'Day')),false).'\'');
		if(isset($this->tb)) {
			$this->display_module($this->tb);
			$this->tb->tag();
		} else {
			$kk = array_keys($this->settings['views']);
			$v = $this->settings['views'][$kk[0]];
			if(!in_array($v,self::$views))
				trigger_error('Invalid view: '.$v.' - '.print_r(self::$views,true),E_USER_ERROR);
			call_user_func(array($this,strtolower($v)));
		}

		Base_ActionBarCommon::add('add','Add event',$this->create_unique_href(array('action'=>'add','time'=>$this->date)));
		Utils_ShortcutCommon::add(array('Ctrl','N'), 'function(){'.$this->create_unique_href_js(array('action'=>'add','time'=>$this->date)).'}');
	}

	public function push_event_action($action,$arg=null) {
		$x = ModuleManager::get_instance('/Base_Box|0');
		if(!$x) trigger_error('There is no base box module instance',E_USER_ERROR);
		$x->push_main($this->event_module,$action,$arg);
	}

	public function delete_event($id) {
		call_user_func(array($this->event_module.'Common','delete'),$id);
	}

	public function move_event($ev_id,$time) {
		if(!is_numeric($time)) $time = strtotime($time);
		$ev = call_user_func(array($this->event_module.'Common','get'),$ev_id);
		if(!$ev['timeless'])
			$time += $ev['start']-strtotime(date('Y-m-d',$ev['start']));
		elseif(!isset($ev['custom_row_key']))
			$ev['custom_row_key'] = 'timeless';
		call_user_func(array($this->event_module.'Common','update'),$ev_id,$time,$ev['duration'],isset($ev['custom_row_key'])?$ev['custom_row_key']:null);
	}

	/**
	 * Get array of events between start and end time.
	 * Array has keys:
	 * - start - start time in seconds 1970
	 * - duration - duration in seconds
	 * - end - end time in seconds 1970
	 * - timeless - please treat start and end time as date
	 * - title
	 * - description
	 *
	 * @return array
	 */
	private function get_events($start,$end) {
		if(!is_numeric($start) && is_string($start)) $start = strtotime($start);
		if(!is_numeric($end) && is_string($end)) $end = strtotime($end);

		$ret = call_user_func(array($this->event_module.'Common','get_all'),$start,$end);
		if(!is_array($ret))
			trigger_error('Invalid return of event method: get (not an array)',E_USER_ERROR);
		return $ret;
	}

	private function print_event($ev,$mode='') {
		print('<div id="utils_calendar_event:'.$ev['id'].'" class="utils_calendar_event">');
		Utils_CalendarCommon::print_event($ev,$mode);
		print('</div>');
	}

	//////////////////////////////////////////////
	// agenda
	public function agenda() {
		$theme = $this->pack_module('Base/Theme');

		/////////////// controls ////////////////////////
		$start = & $this->get_module_variable('agenda_start',$this->date);
		$end = & $this->get_module_variable('agenda_end',$this->date + (7 * 24 * 60 * 60));

		$form = $this->init_module('Libs/QuickForm',null,'agenda_frm');

		$form->addElement('datepicker', 'start', $this->lang->t('From'));
		$form->addElement('datepicker', 'end', $this->lang->t('To'));
		$form->addElement('submit', 'submit_button', $this->lang->ht('Show'));
		$form->addRule('select_start', 'Field required', 'required');
		$form->addRule('select_end', 'Field required', 'required');
		$form->setDefaults(array('start'=>$start,'end'=>$end));

		if($form->validate()) {
			$data = $form->exportValues();
			$start = strtotime($data['start']);
			$end = strtotime($data['end']);
		}

		$form->assign_theme('form', $theme, new HTML_QuickForm_Renderer_TCMSArraySmarty());

		//////////////// data ////////////////////////
		$gb = $this->init_module('Utils/GenericBrowser', null, 'agenda');
		$columns = array(
			array('name'=>$this->lang->t('Start'), 'order'=>'start', 'width'=>10),
			array('name'=>$this->lang->t('Duration'), 'order'=>'end', 'width'=>5),
			array('name'=>$this->lang->t('Title'), 'order'=>'title','width'=>10));
		$add_cols = array();
		if(is_array($this->settings['custom_agenda_cols'])) {
			$w = 50/count($this->settings['custom_agenda_cols']);
			foreach($this->settings['custom_agenda_cols'] as $k=>$col) {
				$columns[] = array('name'=>$this->lang->t($col), 'order'=>'cus_col_'.$k,'width'=>$w);
				$add_cols[] = $k;
			}
		}

		$gb->set_table_columns( $columns );

		//add data
		$ret = $this->get_events($start,$end);
		$this->displayed_events = array('start'=>$start, 'end'=>$end,'events'=>$ret);
		foreach($ret as $row) {
			$r = $gb->get_new_row();
			$view_h = $this->create_callback_href(array($this,'push_event_action'),array('view',$row['id']));

			$ex = Utils_CalendarCommon::process_event($row);

			$rrr = array($ex['start'],Utils_TooltipCommon::create($ex['duration'],$ex['end'],false),'<a '.$view_h.'>'.$row['title'].'</a>');
			foreach($add_cols as $a)
				$rrr[] = $row['custom_agenda_col_'.$a];

			$r->add_data_array($rrr);

			if($row['additional_info']!=='' || $row['additional_info2'])
				$r->add_info($row['additional_info'].(($row['additional_info']!=='' && $row['additional_info2']!=='')?'<hr>':'').$row['additional_info2']);

			$r->add_action($this->create_confirm_callback_href($this->lang->ht('Delete this event?'),array($this,'delete_event'),$row['id']),'Delete');
			$r->add_action($this->create_callback_href(array($this,'push_event_action'),array('edit',$row['id'])),'Edit');
			$r->add_action($view_h,'View');
		}

		$theme->assign('agenda',$this->get_html_of_module($gb,array(false),'automatic_display'));

		//////////////// display ///////////////
		$theme->display('agenda');
	}

	////////////////////////////////////////////////////////////////////
	// day
	public function day() {
		$theme = & $this->pack_module('Base/Theme');

		$theme->assign('trash_label', $this->lang->ht('Drag and drop<br>to delete'));
		$theme->assign('next_href', $this->create_unique_href(array('date'=>date('Y-m-d',$this->date+86400))));
		$theme->assign('next_label',$this->lang->ht('Next day'));
		$theme->assign('today_href', $this->create_unique_href(array('date'=>date('Y-m-d'))));
		$theme->assign('today_label', $this->lang->ht('Today'));
		$theme->assign('prev_href', $this->create_unique_href(array('date'=>date('Y-m-d',$this->date-86400))));
		$theme->assign('prev_label', $this->lang->ht('Previous day'));
		$theme->assign('info', $this->lang->t('Double&nbsp;click&nbsp;on&nbsp;cell&nbsp;to&nbsp;add&nbsp;event'));
		$link_text = $this->create_unique_href_js(array('date'=>'__YEAR__-__MONTH__-__DAY__'));
		$theme->assign('popup_calendar', Utils_PopupCalendarCommon::show('day_selector', $link_text,false,'day',$this->settings['first_day_of_week']));

		$header_day = array('number'=>date('d',$this->date),
							'label'=>$this->lang->t(date('l',$this->date)),
							'label_short'=>$this->lang->t(date('D',$this->date))
							);

		$theme->assign('header_month', $this->lang->t(date('F',$this->date)));
		$theme->assign('link_month', $this->create_unique_href(array('action'=>'switch','time'=>$this->date, 'tab'=>'Month')));
		$theme->assign('header_year', date('Y',$this->date));
		$theme->assign('link_year', $this->create_unique_href(array('action'=>'switch','time'=>$this->date, 'tab'=>'Year')));
		$theme->assign('header_day', $header_day);

		$timeline = $this->get_timeline();
		$today_t = Base_RegionalSettingsCommon::reg2time(date('Y-m-d',$this->date));
		$dnd = array();
		$joins = array();
		$prev = null;
		foreach($timeline as & $v) {
			if(is_string($v['time'])) {
				$ii = $today_t.'_'.$v['time'];
				$dnd[] = $ii;
				if($prev && isset($prev['join_rows'])) $joins[count($joins)-1][2] = $ii;
				if(isset($v['join_rows']))
					$joins[] = array($ii,$v['join_rows'],0);
				$v['id'] = 'UCcell_'.$ii;
			} else {
				$ii = $today_t+$v['time'];
				$dnd[] = $ii;
				if($prev && isset($prev['join_rows'])) $joins[count($joins)-1][2] = $ii;
				if(isset($v['join_rows']))
					$joins[] = array($ii,$v['join_rows'],0);
				$v['id'] = 'UCcell_'.$ii;
			}
			$prev = & $v;
		}
		$this->js('Utils_Calendar.join_rows(\''.Epesi::escapeJS(json_encode($joins),false).'\')');

		$theme->assign('timeline', $timeline);

		$theme->assign('day_view_label', $this->lang->t('Day calendar'));

		$theme->assign('weekend', date('N',$this->date)>=6);

		$theme->assign('trash_id','UCtrash');

		$theme->display('day');

		//data
		$this->date = strtotime(date('Y-m-d 00:00:00',$this->date));
		$start = Base_RegionalSettingsCommon::reg2time($this->date);
		$end = Base_RegionalSettingsCommon::reg2time($this->date+2*86400);
		$ret = $this->get_events($start, $end);
		$this->displayed_events = array('start'=>$start, 'end'=>$end,'events'=>$ret);
		$custom_keys = $this->settings['custom_rows'];
		$this->js('Utils_Calendar.page_type=\'day\'');
		$ev_out = 'function() {Utils_Calendar.init_reload_event_tag();';
		foreach($ret as $ev) {
			$ev_start = $ev['start']-$today_t;//Base_RegionalSettingsCommon::reg2time(date('Y-m-d H:i:s',$ev['start']))
			if($ev_start<0 || $ev_start>=86400) continue;

			if(isset($ev['timeless']) && $ev['timeless'] && !isset($ev['custom_row_key'])) {
				$ev['custom_row_key'] = 'timeless';
			}
			if(isset($ev['custom_row_key'])) {
				if(isset($custom_keys[$ev['custom_row_key']])) {
					$dest_id = 'UCcell_'.$today_t.'_'.$ev['custom_row_key'];
				} else {
//					trigger_error('Invalid custom_row_key:'.$ev['custom_row_key'],E_USER_ERROR);
					continue;
				}
			} elseif($this->settings['timeline']) {
				$ct = count($timeline);
				for($i=1, $j=2; $j<$ct; $i++,$j++)
					if($timeline[$i]['time']<=$ev_start && $ev_start<$timeline[$j]['time'])
						break;
				$dest_id = $timeline[$i]['id'];
			}
			if(isset($dest_id)) {
				$this->print_event($ev,'day');
				$ev_out .= 'Utils_Calendar.add_event(\''.Epesi::escapeJS($dest_id,false).'\',\''.$ev['id'].'\', '.((!isset($ev['draggable']) || $ev['draggable']==true)?1:0).', '.ceil($ev['duration']/(strtotime($this->settings['interval'])-strtotime('0:00'))).');';
			}
		}
		$ev_out.='Utils_Calendar.flush_reload_event_tag();}';
		eval_js('setTimeout('.$ev_out.',300)');
		eval_js('Utils_Calendar.activate_dnd(\''.Epesi::escapeJS(json_encode($dnd),false).'\','.
				'\''.Epesi::escapeJS($this->create_unique_href_js(array('action'=>'add','time'=>'__TIME__','timeless'=>'__TIMELESS__')),false).'\','.
				'\''.Epesi::escapeJS($this->get_path(),false).'\','.
				'\''.CID.'\')');
	}

	///////////////////////////////////////////////////////
	// week
	public function shift_week_day($s) { //true=+1,false=-1
		$sh = & $this->get_module_variable('week_shift',0);
		if($s) {
			$sh++;
			if($sh==7) {
				$sh=0;
				$this->date+=604800; //next week
			}
		} else {
			$sh--;
			if($sh==-7) {
				$sh=0;
				$this->date-=604800; //prev week
			}
		}
	}
	public function set_week_date($d) {
		$this->set_date($d);
		$this->set_module_variable('week_shift',0);
	}

	public function week() {
		$theme = & $this->pack_module('Base/Theme');

		$theme->assign('trash_label', $this->lang->ht('Drag and drop<br>to delete'));
		$theme->assign('next7_href', $this->create_unique_href(array('date'=>date('Y-m-d',$this->date+604800))));
		$theme->assign('next7_label',$this->lang->ht('Next week'));
		$theme->assign('next_href', $this->create_unique_href(array('shift_week_day'=>1)));
		$theme->assign('next_label',$this->lang->ht('Next day'));
		$theme->assign('today_href', $this->create_unique_href(array('date'=>date('Y-m-d'))));
		$theme->assign('today_label', $this->lang->ht('Today'));
		$theme->assign('prev_href', $this->create_unique_href(array('shift_week_day'=>0)));
		$theme->assign('prev_label', $this->lang->ht('Previous day'));
		$theme->assign('prev7_href', $this->create_unique_href(array('date'=>date('Y-m-d',$this->date-604800))));
		$theme->assign('prev7_label', $this->lang->ht('Previous week'));
		$theme->assign('info', $this->lang->t('Double&nbsp;click&nbsp;on&nbsp;cell&nbsp;to&nbsp;add&nbsp;event'));
		$link_text = $this->create_unique_href_js(array('week_date'=>'__YEAR__-__MONTH__-__DAY__'));
		$theme->assign('popup_calendar', Utils_PopupCalendarCommon::show('week_selector', $link_text,false,'day',$this->settings['first_day_of_week']));

		$week_shift = 86400*$this->get_module_variable('week_shift',0);

		$first_day_of_displayed_week = date('w', $this->date)-$this->settings['first_day_of_week'];
		if ($first_day_of_displayed_week<0) $first_day_of_displayed_week += 7;
		$first_day_of_displayed_week *= 86400;
		$dis_week_from = strtotime(date('Y-m-d 00:00:00',$this->date+$week_shift-$first_day_of_displayed_week));
		$dis_week_from = Base_RegionalSettingsCommon::reg2time($dis_week_from);

		//headers
		$day_headers = array();
		$today = date('Y-m-d',strtotime(Base_RegionalSettingsCommon::time2reg(null,false)));
		if (date('m',$dis_week_from)!=date('m',$dis_week_from+518400)) {
			$second_span_width = date('d',$dis_week_from+518400);
			$header_month = array('first_span'=>array(
									'colspan'=>7-$second_span_width,
									'month'=>$this->lang->t(date('M',$dis_week_from)),
									'month_link'=>$this->create_unique_href(array('action'=>'switch','time'=>$dis_week_from, 'tab'=>'Month')),
									'year'=>date('Y',$dis_week_from),
									'year_link'=>$this->create_unique_href(array('action'=>'switch','time'=>$dis_week_from, 'tab'=>'Year'))),
								'second_span'=>array(
									'colspan'=>$second_span_width,
									'month'=>$this->lang->t(date('M',$dis_week_from+518400)),
									'month_link'=>$this->create_unique_href(array('action'=>'switch','time'=>$dis_week_from+518400, 'tab'=>'Month')),
									'year'=>date('Y',$dis_week_from+518400),
									'year_link'=>$this->create_unique_href(array('action'=>'switch','time'=>$dis_week_from+518400, 'tab'=>'Year'))
									));
		} else {
			$header_month = array('first_span'=>array(
									'colspan'=>7,
									'month'=>$this->lang->t(date('M',$dis_week_from)),
									'month_link'=>$this->create_unique_href(array('action'=>'switch','time'=>$dis_week_from, 'tab'=>'Month')),
									'year'=>date('Y',$dis_week_from),
									'year_link'=>$this->create_unique_href(array('action'=>'switch','time'=>$dis_week_from, 'tab'=>'Year'))),
									);
		}
		for ($i=0; $i<7; $i++) {
			$that_day = $dis_week_from+$i*86400;
			$day_headers[] = array(
						'date'=>date('d', $that_day).' '.$this->lang->t(date('D', $that_day)),
						'style'=>(date('Y-m-d',$that_day)==$today?'today':'other').(date('N',$that_day)>=6?'_weekend':''),
						'link' => $this->create_unique_href(array('action'=>'switch','time'=>$that_day, 'tab'=>'Day'))
						);
		}

		$theme->assign('header_month', $header_month);
		$theme->assign('day_headers', $day_headers);

		//timeline and ids
		$timeline = $this->get_timeline();
		$time_ids = array();
		$dnd = array();
		$joins = array();
		for ($i=0; $i<7; $i++) {
			$time_ids[$i] = array();
			$today_t_timeless = strtotime(date('Y-m-d',$dis_week_from+$i*86400));
			$today_t = Base_RegionalSettingsCommon::reg2time(date('Y-m-d',$dis_week_from+$i*86400));
			$prev = null;
			foreach($timeline as & $v) {
				if(is_string($v['time'])) {
					$ii = $today_t_timeless.'_'.$v['time'];
					$dnd[] = $ii;
					if($prev && isset($prev['join_rows'])) $joins[count($joins)-1][2] = $ii;
					if(isset($v['join_rows']))
						$joins[] = array($ii,$v['join_rows'],0);
					$time_ids[$i][] = 'UCcell_'.$ii;
				} else {
					$ii = $today_t+$v['time'];
					$dnd[] = $ii;
					if($prev && isset($prev['join_rows'])) $joins[count($joins)-1][2] = $ii;
					if(isset($v['join_rows']))
						$joins[] = array($ii,$v['join_rows'],0);
					$time_ids[$i][] = 'UCcell_'.$ii;
				}
				$prev = $v;
			}
		}
		$this->js('Utils_Calendar.join_rows(\''.Epesi::escapeJS(json_encode($joins),false).'\')');
		$theme->assign('time_ids', $time_ids);
		$theme->assign('timeline', $timeline);

		$theme->assign('week_view_label', $this->lang->t('Week calendar'));
		$theme->assign('trash_id','UCtrash');
		//ok, display
		$theme->display('week');

		//data
		$dis_week_to = $dis_week_from+7*86400-1;
		$ret = $this->get_events($dis_week_from,$dis_week_to);
		$this->displayed_events = array('start'=>$dis_week_from, 'end'=>$dis_week_to,'events'=>$ret);
		$custom_keys = $this->settings['custom_rows'];
		$this->js('Utils_Calendar.page_type=\'week\'');
		$ev_out = 'function() {Utils_Calendar.init_reload_event_tag();';
		foreach($ret as $k=>$ev) {
			if(isset($ev['timeless']) && $ev['timeless'] && !isset($ev['custom_row_key'])) {
				$ev['custom_row_key'] = 'timeless';
			}
			if(isset($ev['custom_row_key'])) {
				$today_t_timeless = strtotime(date('Y-m-d',$ev['start']));
				if(isset($custom_keys[$ev['custom_row_key']])) {
					$dest_id = 'UCcell_'.$today_t_timeless.'_'.$ev['custom_row_key'];
				} else {
//					trigger_error('Invalid custom_row_key:'.$ev['custom_row_key'],E_USER_ERROR);
					continue;
				}
			} else {
				$today_t = Base_RegionalSettingsCommon::time2reg(date('Y-m-d H:i:s',$ev['start']),true,true,true,false);
				$today_t = strtotime(date('Y-m-d H:i:s',Base_RegionalSettingsCommon::reg2time(date('Y-m-d',strtotime($today_t)))));
				$ev_start = $ev['start']-$today_t;
				$ct = count($timeline);
				for($i=1, $j=2; $j<$ct; $i++,$j++)
					if($timeline[$i]['time']<=$ev_start && $ev_start<$timeline[$j]['time'])
						break;
				$dest_id = 'UCcell_'.($today_t+$timeline[$i]['time']);
			}
			if(isset($dest_id)) {
//				print($ev['title'].' '.$ev['start'].'<hr>');
				$this->print_event($ev);
//				$this->js('Utils_Calendar.add_event(\''.Epesi::escapeJS($dest_id,false).'\', \''.$ev['id'].'\', '.((!isset($ev['draggable']) || $ev['draggable']==true)?1:0).', '.ceil($ev['duration']/(strtotime($this->settings['interval'])-strtotime('0:00'))).')');
				$ev_out .= 'Utils_Calendar.add_event(\''.Epesi::escapeJS($dest_id,false).'\', \''.$ev['id'].'\', '.((!isset($ev['draggable']) || $ev['draggable']==true)?1:0).', '.ceil($ev['duration']/(strtotime($this->settings['interval'])-strtotime('0:00'))).');';
			}
		}
		$ev_out.='Utils_Calendar.flush_reload_event_tag();}';
		eval_js('setTimeout('.$ev_out.',300)');
		eval_js('Utils_Calendar.activate_dnd(\''.Epesi::escapeJS(json_encode($dnd),false).'\','.
				'\''.Epesi::escapeJS($this->create_unique_href_js(array('action'=>'add','time'=>'__TIME__','timeless'=>'__TIMELESS__')),false).'\','.
				'\''.Epesi::escapeJS($this->get_path(),false).'\','.
				'\''.CID.'\')');
	}

	//////////////////////////////////////////////////////
	// month and year
	public function month_array($date, $mark = array(), & $it = 0) {
		$first_day_of_month = strtotime(date('Y-m-', $date).'01');
		$diff = date('w', $first_day_of_month)-$this->settings['first_day_of_week'];
		if ($diff<0) $diff += 7;
		$currday = $first_day_of_month-86400*($diff);
		$curmonth = date('m', $date);

		$month = array();
		$today = date('Y-m-d',strtotime(Base_RegionalSettingsCommon::time2reg(null,false)));
		$colors = CRM_Calendar_EventCommon::get_available_colors();
		while (date('m', $currday) != ($curmonth)%12+1) {
			$week = array();
			$weekno = date('W',$currday);
			$link = $this->create_unique_href(array('action'=>'switch','time'=>$currday, 'tab'=>'Week'));
			for ($i=0; $i<7; $i++) {
				$main_month = date('m', $currday)==$curmonth;
				$next = array(
							'day'=>date('j', $currday),
							'day_link' => $this->create_unique_href(array('action'=>'switch', 'time'=>$currday, 'tab'=>'Day')),
							'style'=>($main_month?(date('Y-m-d',$currday)==$today?'today':'current'):'other').(date('N',$currday)>=6?'_weekend':''),
							'time'=>$currday
							);
				if ($main_month && isset($mark[$it]) && $currday == $mark[$it]['time']) {
					$next['style'].= ' event-'.$colors[$mark[$it]['color']];
					$it++;
				}
				$week[] = $next;
				$currday += 86400;
				$currday = strtotime(date('Y-m-d 00:00:00', $currday+60*60*12));
			}
			$month[] = array(
							'week_label'=>$weekno,
							'week_link' => $link,
							'days'=>$week);
		}
		return $month;
	}

	public function month() {
		$theme = & $this->pack_module('Base/Theme');

		$theme->assign('trash_label', $this->lang->ht('Drag and drop<br>to delete'));
		$theme->assign('nextyear_href', $this->create_unique_href(array('date'=>(date('Y',$this->date)+1).date('-m-d',$this->date))));
		$theme->assign('nextyear_label',$this->lang->ht('Next year'));
		$theme->assign('nextmonth_href', $this->create_unique_href(array('date'=>date('Y-m-d',$this->date+86400*date('t',$this->date)))));
		$theme->assign('nextmonth_label',$this->lang->ht('Next month'));
		$theme->assign('today_href', $this->create_unique_href(array('date'=>date('Y-m-d'))));
		$theme->assign('today_label', $this->lang->ht('Today'));
		$theme->assign('prevmonth_href', $this->create_unique_href(array('date'=>date('Y-m-d',$this->date-86400*date('t',$this->date-86400*(date('d', $this->date)+1))))));
		$theme->assign('prevmonth_label', $this->lang->ht('Previous month'));
		$theme->assign('prevyear_href', $this->create_unique_href(array('date'=>(date('Y',$this->date)-1).date('-m-d',$this->date))));
		$theme->assign('prevyear_label', $this->lang->ht('Previous year'));
		$theme->assign('info', $this->lang->t('Double&nbsp;click&nbsp;on&nbsp;cell&nbsp;to&nbsp;add&nbsp;event'));

		if ($this->isset_unique_href_variable('date'))
			$this->set_date($this->get_unique_href_variable('date'));

		$link_text = $this->create_unique_href_js(array('date'=>'__YEAR__-__MONTH__-__DAY__'));
		$theme->assign('popup_calendar', Utils_PopupCalendarCommon::show('week_selector', $link_text,false,'month'));

		$month = $this->month_array($this->date);
		$dnd = array();
		foreach($month as & $week) {
			foreach($week['days'] as & $day) {
				$day['id'] = 'UCcell_'.$day['time'];
				$dnd[] = $day['time'];
			}
		}

		$day_headers = array();
		for ($i=0; $i<7; $i++) {
			$time = strtotime('Sun')+86400*($i+$this->settings['first_day_of_week']);
			$day_headers[] = array('class'=>(date('N',$time)>=6?'weekend_day_header':'day_header'), 'label'=>$this->lang->t(date('D', $time)));
		}

		$theme->assign('month_view_label', $this->lang->t('Month calendar'));

		$theme->assign('day_headers', $day_headers);
		$theme->assign('month', $month);
		$theme->assign('month_label', $this->lang->t(date('F', $this->date)));
		$theme->assign('year_label', date('Y', $this->date));
		$theme->assign('year_link', $this->create_unique_href(array('time'=>$this->date, 'tab'=>'Year','action'=>'switch')));
		$theme->assign('trash_id','UCtrash');

		$theme->display('month');


		//data
		$start_t = $month[0]['days'][0]['time'];
		$start_t = Base_RegionalSettingsCommon::reg2time($start_t);
		$end_t = $month[count($month)-1]['days'][6]['time']+86400;
		$end_t = Base_RegionalSettingsCommon::reg2time($end_t);
		$ret = $this->get_events($start_t,$end_t);
		$this->js('Utils_Calendar.page_type=\'month\'');
		$ev_out = 'function() {';
		foreach($ret as $k=>$ev) {
			$this->print_event($ev);
			if(!isset($ev['timeless']) || !$ev['timeless'])
				$ev_start = strtotime(Base_RegionalSettingsCommon::time2reg($ev['start'],true,true,true,false));
			else
				$ev_start = $ev['start'];
			$ev_start = strtotime(date('Y-m-d',$ev_start));
			$dest_id = 'UCcell_'.$ev_start;
			$ev_out .= 'Utils_Calendar.add_event(\''.Epesi::escapeJS($dest_id,false).'\', \''.$ev['id'].'\', '.((!isset($ev['draggable']) || $ev['draggable']==true)?1:0).', 1);';
		}
		$ev_out.='}';
		eval_js('setTimeout('.$ev_out.',300)');
		eval_js('Utils_Calendar.activate_dnd(\''.Epesi::escapeJS(json_encode($dnd),false).'\','.
				'\''.Epesi::escapeJS($this->create_unique_href_js(array('action'=>'add','time'=>'__TIME__','timeless'=>'__TIMELESS__')),false).'\','.
				'\''.Epesi::escapeJS($this->get_path(),false).'\','.
				'\''.CID.'\')');
	}

	public function year() {
		$theme = & $this->pack_module('Base/Theme');

		$theme->assign('nextyear_href', $this->create_unique_href(array('date'=>(date('Y',$this->date)+1).date('-m-d',$this->date))));
		$theme->assign('nextyear_label',$this->lang->ht('Next year'));
		$theme->assign('today_href', $this->create_unique_href(array('date'=>date('Y-m-d'))));
		$theme->assign('today_label', $this->lang->ht('Today'));
		$theme->assign('prevyear_href', $this->create_unique_href(array('date'=>(date('Y',$this->date)-1).date('-m-d',$this->date))));
		$theme->assign('prevyear_label', $this->lang->ht('Previous year'));
		$theme->assign('info', $this->lang->t('Double&nbsp;click&nbsp;on&nbsp;cell&nbsp;to&nbsp;add&nbsp;event'));

		$link_text = $this->create_unique_href_js(array('date'=>'__YEAR__-__MONTH__-__DAY__'));
		$theme->assign('popup_calendar', Utils_PopupCalendarCommon::show('week_selector', $link_text,false,'year'));


		$day_headers = array();
		for ($i=0; $i<7; $i++)
			$day_headers[] = $this->lang->t(date('D', strtotime('Sun')+86400*($i+$this->settings['first_day_of_week'])));

		$theme->assign('month_view_label', $this->lang->t('Year calendar'));

		$year = array();

		$ret = call_user_func(array($this->event_module.'Common','get_event_days'),date('Y-01-01',$this->date),date('Y-12-31',$this->date));

		$it = 0;
		for ($i=1; $i<=12; $i++) {
			$date = strtotime(date('Y',$this->date).'-'.str_pad($i, 2, '0', STR_PAD_LEFT).'-15');
			$month = $this->month_array($date, $ret, $it);
			$year[] = array('month' => $month,
							'month_link' => $this->create_unique_href(array('action'=>'switch','time'=>$date, 'tab'=>'Month')),
							'month_label' => $this->lang->t(date('F', $date)),
							'year_label' => date('Y', $date)
							);
		}
		$theme->assign('year', $year);
		$theme->assign('day_headers', $day_headers);

		$theme->display('year');
	}

	public function get_displayed_events() {
		return $this->displayed_events;
	}

	////////////////////////////////////////
	public function caption() {
		return 'Calendar';
	}
}
?>
