<?php
/* behdadcalendar.php - A PROTOTYPE for multiple-system calendar widget
 *
 * Copyright (C) 2005--2010  Behdad Esfahbod
 *
 * Author: Behdad Esfahbod (http://behdad.org/)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 */

/*
 * WARNING: This is just a prototype.  Nothing serious.  Known problems:
 *
 * - The Islamic calnedar is really just a stub that works for around the
 *   time I wrote this.  It definitely goes out of sync in a year or two
 *   before or after...
 * - The entire thing is again, just a prototype.  Instead of having a real
 *   converter between calendar systems, it just enumerates the dates from
 *   a fixed point up to the requested date...  It may bring your server
 *   down if somebody feels like DoS using it.
 * - The architecture is a mess, but ispiring.  Somebody should actually
 *   reimplment this in C for Gtk+...
 * - As the license says, WITHOUT ANY WARRANTY.
 *
 *	-- Behdad Esfahbod
 *
 * ChangeLog:
 *   July/August 2008: Added Google Widget support
 *   October 2009: Added Facebook App support
 *   December 2010: Adjust GoogleGadget support to fix issues with Gmail
 */

/*
 * Iranian holidays got from http://www.farsiweb.info/table/iran-holidays.txt
 *
 * TODO:
 *
 * - Martyrdom of Imam Reza is on Safar 30, but if Safar is 29 days long,
 *   it goes on 29th.  It's a general rule: any event past the end of the
 *   month should be truncated to the last day of the month.
 * - Make several calendars in a page possible: prefix args, session, etc.
 * - Draw "today" box around month and year labels too.
 * - Detect language from user request.  Moreover, choose a subcalendar
 *   matching this language (add an option for it).
 * - Support hiding/not-showing subcalendars.
 * - Expose options in UI.
 * - Support systems with: (no)session, (no)cookies, ...
 * - Show annotations for today.
 * - Make it all JavaScript instead of PHP
 * - Use javascript Date() plus cookies to detect user timezone
 *
 */

/*abstract*/ class Calendar {

  var $i; // index, number of days since Unix epoch

  /* is automatically set */
  var $w; // day-of-week, zero for Sat

  /* not really variables */
  var $week_length = 7;
  var $week_day_names = array (
      'Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday',
      );

  var $week_start; // zero for Sat
  var $weekend = array (); // array of weekend holidays

  var $unit = array (
      'i' => 'day',
      'w' => 'day',
      'd' => 'day',
      'm' => 'month',
      'y' => 'year',
      );

  function Calendar () {
    // This constructor should be chained after setting $i.
    $this->synch ();
  }

  /* Returns string prepared for HTML (htmlspeacialchars'ed, but not br2nl'ed */
  function str ($lang = '', $part = '') {
    switch ($part) {
      case 'w': return N_($this->week_day_names[$this->w], $lang);
      case 'i': return NUM_($this->i, $lang);
      case 'week_day':	return $this->str ($lang, 'w');
      case 'index':	return $this->str ($lang, 'i');
      case '':
	return $this->str ($lang, 'w').N_(",").
	       $this->str ($lang, 'i');
      default:
	return 'invalid requested part';
    }
  }

  function is_weekend () {
    return !(false === array_search ($this->w, $this->weekend));
  }

  function is_other_holiday () {
    return false;
  }

  function is_holiday () {
    return $this->is_weekend () or $this->is_other_holiday ();
  }

  /* Callback to allow deactivating particular dates, e.g. no blog entry, etc */
  function is_inactive () {
    return false;
  }

  function get_annotations () {
    return array ();
  }

  function synch () {
    $this->w = (5 + $this->i) % $this->week_length;
  }

  function get_unit ($what) {
    $what = (string) $what;
    return $this->unit[$what[0]];
  }

  function move ($n, $what = 'i', $minimal_move = false) {
    if (!$n)
      return;
    $unit = $this->get_unit ($what);
    $move = "move_${unit}s";
    $this->$move ($n, $minimal_move);
  }

  function move_to ($n, $what = 'i') {
    $this->move ($n - $this->$what, $what);
  }

  function move_years ($n, $minimal_move = false) {
    $this->move ($n, 'y', $minimal_move);
  }

  function move_months ($n, $minimal_move = false) {
    $this->move ($n, 'm', $minimal_move);
  }

  function move_days ($n, $minimal_move = false) {
    $this->move ($n, 'd', $minimal_move);
  }
  function move_today () {
    $this->move_to ((int)(date ('U') / 86400));
  }

  function set_week_start ($week_start) {
    $this->week_start = $week_start;
  }

  var $i_stack = array ();

  function save () {
    array_push ($this->i_stack, $this->i);
  }

  function restore () {
    $this->move_to (array_pop ($this->i_stack));
  }
}

/*abstract*/ class SimpleCalendar extends Calendar {

  var $y; // year
  var $m; // month, starting at one
  var $d; // day-in-month, starting at one

  /* not really variables */

  /* these two are computed automatically */
  var $max_month_length;
  var $year_length; // without leap year consideration

  var $month_name = array ('err'); // starts at one
  var $lang;

  var $month_num = 12;

  var $month_length = array (0); // starts at one. without leap year consideration
  var $leap_month = 0; // which month gets leap day added to (zero if not applicable)

  var $holi = array (); // array of array of holidays for days of months
  var $annot = array (); // array of array of array of annotations for days of months

  /* base */
  var $base_y;
  var $base_m;
  var $base_d;
  var $base_i;


  function SimpleCalendar () {
    $this->y = $this->base_y;
    $this->m = $this->base_m;
    $this->d = $this->base_d;
    $this->i = $this->base_i;
    $this->max_month_length = max (max ($this->month_length),
	                           1 + $this->month_length[$this->leap_month]);
    $this->year_length = 0;
    foreach ($this->month_length as $l)
      $this->year_length += $l;

    parent::Calendar ();
  }

  function is_leap_year () {
    return false;
  }

  function str ($lang = '', $part = '') {
    switch ($part) {
      case 'y': return NUM_($this->y, $lang);
      case 'm': return N_($this->month_name[$this->m], $lang);//NUM_($this->m, $lang);
      case 'd': return NUM_($this->d, $lang);
      case 'year':	return $this->str ($lang, 'y');
      case 'month':	return $this->str ($lang, 'm');
      case 'day':	return $this->str ($lang, 'd');
      case '':
	return $this->str ($lang, 'd')." ".
	       $this->str ($lang, 'm')." ".
	       $this->str ($lang, 'y');
      default:
	return parent::str ($lang, $part);
    }
  }

  /* is a holiday according to this calendar? */
  function is_other_holiday () {
    return isset ($this->holi[$this->m]) &&
           !(false === array_search ($this->d, $this->holi[$this->m]));
  }

  function get_annotations () {
    if (!isset ($this->annot[$this->m][$this->d]))
      return array ();

    return $this->annot[$this->m][$this->d];
  }

  /* returns the length of current year */
  function get_year_length () {
    return $this->year_length + $this->is_leap_year ();
  }

  /* returns the length of current month */
  function get_month_length () {
    return ($this->m == $this->leap_month && $this->is_leap_year() ? 1 : 0) +
            $this->month_length[$this->m];
  }

  var $synch_sem = 0;

  function move_years ($n, $minimal_move = false) {
    if (!$n)
      return;

    $this->synch_sem++;

    $d = $this->d;
    if ($minimal_move)
      $n_months = (abs ($n) - 1) * $this->month_num
                + $n < 0 ? -$this->m : $this->month_num - $this->m + 1;
    else
      $n_months = $n * $this->month_num;

    $this->move_months ($n_months, $minimal_move);

    $l = $this->get_month_length ();
    if (!$minimal_move && $this->d != $d && $this->d < $l)
      $this->move_days (min($d, $l) - $this->d);

    if (!--$this->synch_sem);
      $this->synch ();
  }

  function move_months ($n, $minimal_move = false) {
    if (!$n)
      return;

    $this->synch_sem++;

    $d = $this->d;
    if ($n < 0)
      $this->move_days ($this->get_month_length () - $this->d);
    else
      $this->move_days (1 - $this->d);

    for ($i = abs($n); $i; $i--)
      $this->move_days (($n<0?-1:1) * $this->get_month_length ());

    if (!$minimal_move)
      $this->move_days (min($d, $this->get_month_length ()) - $this->d);

    if (!--$this->synch_sem);
      $this->synch ();
  }

  function move_days ($n, $minimal_move = false) {
    if (!$n)
      return;

    $this->synch_sem++;

    $this->i += $n;
    while ($n) {
      if ($n < 0) {
	/* moving back */
	if (-$n < $this->d) {
	  $this->d += $n;
	  $n = 0;
	} else {
	  $n += $this->d;
	  $this->m--;
	  if (!$this->m) {
	    $this->y--;
	    $this->m = $this->month_num;
	  }
	  $this->d = $this->get_month_length();
	}
      } else {
	/* moving forward */
	if ($n < $this->get_month_length() - $this->d + 1) {
	  $this->d += $n;
	  $n = 0;
	} else {
	  $n -= $this->get_month_length() - $this->d + 1;
	  $this->m++;
	  if ($this->m > $this->month_num) {
	    $this->y++;
	    $this->m = 1;
	  }
	  $this->d = 1;
	}
      }
    }

    if (!--$this->synch_sem);
      $this->synch ();
  }

  function move_to_date ($y, $m, $d) {
    $this->synch_sem++;

    $this->move_years ($y - $this->y);
    $this->move_months ($m - $this->m);
    $this->move_days ($d - $this->d);

    if (!--$this->synch_sem);
      $this->synch ();
  }

  function set_date ($date) {
     $this->move_to_date ($date[0], $date[1], $date[2]);
  }

  function get_date () {
    return array ($this->y, $this->m, $this->d, $this->i);
  }

  /* Returns an array filled with years/months involved in the date range $i1..$i2 inclusive.
     The keys for the array are simply the nearest day in the specific year/month to the
     current date.
     $item should be either 'y' or 'm' (well, it can be 'd, 'w', or 'i' too.) */
  function get_range ($item, $i1, $i2) {
    $i = $this->i;
    $unit = $this->get_unit ($item);
    $move = "move_${unit}s";

    $result = array ();

    $this->save ();

    /* past dates */
    $start = min ($i, $i2);
    $end = $i1;
    $this->move_to ($start);
    while ($end <= $this->i) {
      $result[$this->i] = $this->$item;
      $this->$move (-1, true);
    }

    $result = array_reverse ($result, true);

    /* future dates */
    $start = max ($i, $i1);
    $end = $i2;
    $this->move_to ($start);
    while ($this->i <= $end) {
      $result[$this->i] = $this->$item;
      $this->$move (+1, true);
    }

    $this->restore ();

    return $result;
  }

  function html_day_text ($opts, $lang = '', $klasses = array (), $link = array ()) {
    array_unshift ($klasses, 'day_name');
    return '<table cellspacing="0" cellpadding="0" class="'.implode (' ', $klasses).'"><tr><td>'.
           make_link ($this->str($lang, 'day'), $link).
	   '</td></tr></table>';
  }

  function html_day_td ($opts, $lang = '', $klasses = array (), $link = array ()) {
    $backend = &get_opt($opts, 'backend', null);
    if ($backend == null)
      $backend = &$this;

    $wend = $backend->is_weekend ();
    $anno = $backend->get_annotations ();
    $holi = $backend->is_holiday ();
    $holi_o = $backend->is_other_holiday ();

    if ($holi) $klasses[] = 'holiday';
    if ($wend) $klasses[] = 'weekend';
    if (count($anno)) $klasses[] = 'annotated';
    if ($holi_o) $klasses[] = 'holiday_other';

    /* If no color class set, set it to plain. */
    if (!count (array_intersect ($klasses, array ('selected', 'holiday', 'holiday_other', 'weekend', 'inactive'))))
      $klasses[] = 'plain';
    array_unshift ($klasses, 'day');

    $s = $backend->html_day_text ($opts, $lang, array (), $link);
    $link_text = make_url ($link);

    $s = '<td class="'.implode(' ', $klasses).'"'.
         (count($anno) ? ' title="'.implode(" \n", N_($anno)).'"' : '').
	 ($link_text ? ' onclick="location='."'$link_text'".'"' : '').
         '>'.$s.'</td>'."\n";

    return $s;
  }

  function html_month_range_header ($opts, $lang = '', $klasses = array (), $link = array (), $i1, $i2) {

    array_unshift ($klasses, 'header', 'fit', 'text');
    $s = '<table cellspacing="0" cellpadding="0" class="'.implode(' ', $klasses).'" style="direction: '.dir_($lang).'">';

    $this_i = $this->i;

    foreach (array ('m', 'y') as $item) {
      $s .= "<tr><td>";
      $unit = $this->get_unit ($item);
      $a = array ();
      $range = $this->get_range ($item, $i1, $i2);
      $this->save ();
      foreach ($range as $i=>$val) {
	$this->move_to ($i);
	$a[] = make_link ($this->str($lang, $item),
			     array_merge_recursive ($link,
		               array ('method'=>"move_to", 'param'=>$i)),
			     '',
			     $i == $this_i ? array ('selected') : array ());
      }
      $this->restore ();
      $s .= implode ('&nbsp;&#8211;&nbsp;', $a);
      $s .= "</td></tr>\n";
    }

    $s .= '</table>';
    return $s;
  }

  function html_month_header ($opts, $lang = '', $klasses = array (), $link = array ()) {
    $today = array_merge_recursive ($link, array ('method'=>'move_to', 'param'=>$this->i));
    array_unshift ($klasses, 'header', 'fit', 'text');
    $s = '<table cellspacing="0" cellpadding="0" class="'.implode(' ', $klasses).'" style="direction: '.dir_($lang).'"><tr><td>';

    $ss = array ();
    foreach (array ('m', 'y') as $item) {
      $unit = $this->get_unit ($item);
      $a = array ();
      # numerical items under rtl should get a ltr order, not rtl
      if ($item == 'm' || dir_($lang) == 'ltr')
        $order = array (-1, 0, +1);
      else
        $order = array (+1, 0, -1);
      foreach ($order as $offset) {
	if ($offset) {
	  $this->save ();
	  $this->move ($offset, $item);
	  # use +/- for numerical items, and </> for month names
	  if ($item == 'm')
	    $t = $offset < 0 ? '&lt;' : '&gt;';
	  else
	    $t = $offset < 0 ? '&#8722;' : '+';
	  $a[] = make_link ($t, array_merge_recursive ($today,
		             array ('method'=>"move_${unit}s", 'param'=>$offset)),
			     $this->str($lang, $item),
			     array ('highlight_a'));
	  $this->restore ();
	} else
          $a[] = make_link ($this->str($lang, $item),
                            $today,
			    '',
			    array ("${unit}_name", 'selected'));
      }
      $ss[] = implode ('&nbsp;', $a);
    }
    $s .= implode ('&nbsp;&nbsp;', $ss);

    $s .= '</td></tr></table>';
    return $s;
  }

  function html_month_body ($opts, $lang = '', $klasses = array ()) {
    $backend = &get_opt($opts, 'backend', null);
    if ($backend == null)
      $backend = &$this;

    $highlight_today = get_opt($opts, 'highlight_today', $highlight_today = true);

    array_unshift ($klasses, 'month', 'fit');
    $cs = get_opt($opts, 'cellspacing', $cs = 0);
    $s = '<table cellspacing="'.$cs.'" cellpadding="0" class="'.implode(' ', $klasses).'" style="direction: '.dir_($lang).'">'."\n";

    /* Save selected date information */
    list($y, $m, $d, $i) = $this->get_date ();
    /* Save today date information */
    $this->save ();
    $this->move_today();
    $today_i = $this->i;
    $this->restore();

    $this->save ();
    /* Move to the beginning of the month */
    $this->move_days (1 - $this->d);
    $month_len = $this->get_month_length ();
    $month_beg_i = $this->i;
    /* And move to the beginning of the week, ensuring some free space */
    $this->move_days (($backend->week_start - $this->w + $backend->week_length) % $backend->week_length - $backend->week_length);
    /* Here is the first day we've got to draw */
    $i1 = $this->i;
    /* And the last */
    $fit_rows = get_opt($opts, 'fit_rows', $fit_rows = true);
    if ($fit_rows)
      $i2 = $i1 + ((int)(($month_beg_i - $i1 + $month_len + $backend->week_length) / $backend->week_length)) * $backend->week_length;
    else
      $i2 = $i1 + ((int)(($this->max_month_length+2*$backend->week_length) / $backend->week_length)) * $backend->week_length;
    $this->restore ();

    $backend->save ();

    /* Week heading */
    $s .= '<tr class="week_days">'."\n";
    $week_days = array ();
    for ($week_day = 0; $week_day < $backend->week_length; $week_day++)
        $week_days[] = $week_day;
    foreach (array_merge (
	       array_slice ($week_days, $backend->week_start),
	       array_slice ($week_days, 0, $backend->week_start)
	     ) as $week_day) {
      $t = N_($backend->week_day_names[$week_day]);
      $t = substr_utf8 ($t, 0, 1);
      if ($week_day != $backend->week_start)
         $t = make_link ($t, array ('method'=>"set_week_start", 'param'=>$week_day),
			 N_("Make this start of the week"), array ());
      $s .= '<th class="day text">'.$t."</th>\n";
    }

    /* Main loop */
    for ($backend->move_to ($i1); $backend->i < $i2; $backend->move_days (1)) {

      /* Change weeks? */
      if ($backend->w == $backend->week_start)
        $s .= "</tr>\n".'<tr class="week">'."\n";

      /* No modifiers by default */
      $klasses = array ();
      /* But link, to change current date */
      $link = array ('method'=>'move_to', 'param'=>$this->i);

      if ($highlight_today) {
	/* If drawing today, the real today */
	if ($this->i == $today_i)
	  $klasses[] = 'today';
      }

      /* If drawing the selected day */
      if ($this->i == $i) {
	/* Let them know */
	$klasses[] = 'selected';
	/* And don't link it */
	// $link = array ();
      /* Otherwise, if doesn't belong to this month */
      } else if ($this->m != $m || $this->y != $y) {
	/* Fade it out */
	$klasses[] = 'inactive';
      }

      /* If deactivated */
      if ($backend->is_inactive ()) {
	/* Fade it out */
	$klasses[] = 'inactive';
	/* And don't link it */
	$link = array ();
      }

      /* Ask for it to be drawn */
      $s .= $backend->html_day_td($opts, $lang, $klasses, $link);
    }

    /* Restore current date */
    $backend->restore();
    $s .= "</tr>\n</table>\n";

    return $s;
  }

  function html_month_calendar ($opts, $lang = '', $klasses = array ()) {
    $backend = &get_opt($opts, 'backend', null);
    if ($backend == null)
      $backend = &$this;
    array_unshift ($klasses, 'month_calendar', 'fit');
    return '<table cellspacing="0" cellpadding="0" class="'.implode(' ', $klasses).'" style="direction: '.dir_($lang).'"'.xml_lang_($lang).'>'."\n".
           "<tr><td>".$backend->html_month_header ($opts, $lang)."</td></tr>\n".
           "<tr><td>".$backend->html_month_body ($opts, $lang)."</td></tr>\n".
	   "</table>\n";
  }
}


class GregorianCalendar extends SimpleCalendar {

  var $month_name = array ('err',
      'January', 'February', 'March', 'April', 'May', 'June',
      'July', 'August', 'September', 'October', 'November', 'December');
  var $lang = 'en';

  var $week_start = 1;
  var $month_length = array (0, 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);
  var $leap_month = 2;

  var $base_y = 2005;
  var $base_m = 2;
  var $base_d = 27;
  var $base_i = 12841;

  var $weekend = array (0, 1);
  var $holi = array (12=>array (25));
  var $annot = array (12=>array (25=>array ('Christmas')));

  function is_leap_year () {
    $y = $this->y;
    return !($y%4) && ($y%100) || !($y%400);
  }
}

class PersianCalendar extends SimpleCalendar {

  var $month_name = array ('err',
      'Farvardin', 'Ordibehesht', 'Khordad', 'Tir', 'Mordad', 'Shahrivar',
      'Mehr', 'Aban', 'Azar', 'Dey', 'Bahman', 'Esfand');
  var $lang = 'fa';

  var $week_start = 0;
  var $month_length = array (0, 31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29);
  var $leap_month = 12;

  var $base_y = 1383;
  var $base_m = 12;
  var $base_d = 9;
  var $base_i = 12841;

  var $weekend = array (6);

  function is_leap_year () {
    $y = $this->y;
    /* 33-year cycles, it better matches Iranian rules */
    return (($y+16)%33+33)%33*8%33<8;
  }
}

class PersianCalendar_33 extends PersianCalendar {
  /* Our PersianCalendar proper does 33-year cycles */
}

class PersianCalendar_2820 extends PersianCalendar {

  function is_leap_year () {
    $y = $this->y;
    /* 2820-year cycles, idiots think it's more precise */
    return (((($y)-474)%2820+2820)%2820*31%128<31);
  }
}

class PersianCalendar_Iran extends PersianCalendar_33 {

  var $holi = array (
        1 => array (
          1, # Nowrooz
	  2, # Nowrooz
	  3, # Nowrooz
	  4, # Nowrooz
	  12, # Islamic Republic Day
	  13, # Nature Day
	),
        3 => array (
	  14, # Demise of Imam Khomeini
	  15, # Revolt of 15 Khordad
	),
        11 => array (
	  22, # Victory of Revolution of Iran
	),
        12 => array (
	  29, # Nationalization of Oil Industry
	),
      );
  var $annot = array (
        1 => array (
          1 => array ("Nowrooz"),
	  2 => array ("Nowrooz"),
	  3 => array ("Nowrooz"),
	  4 => array ("Nowrooz"),
	  12 => array ("Islamic Republic Day"),
	  13 => array ("Nature Day"),
	),
        3 => array (
	  14 => array ("Demise of Imam Khomeini"),
	  15 => array ("Revolt of 15 Khordad"),
	),
        11 => array (
	  22 => array ("Victory of Revolution of Iran"),
	),
        12 => array (
	  29 => array ("Nationalization of Oil Industry"),
	),
      );
}

class IslamicCalendar extends SimpleCalendar {

  var $month_name = array ('err',
      "Muharram", "Safar", "Rabi' I", "Rabi' II", "Jumada I", "Jumada II",
      "Rajab", "Sha'ban", "Ramadan", "Shawwal", "Dhu al-Qi'dah", "Dhu al-Hijjah");
  var $lang = 'ar';

  var $week_start = 0;
  var $month_length = array (0, 30, 29, 30, 29, 30, 29, 30, 29, 30, 29, 30, 29);
  var $leap_month = 12;

  var $base_y = 1429;
  var $base_m = 8;
  var $base_d = 4;
  var $base_i = 14096;

  var $weekend = array (6);
  var $annot = array (
	1 => array (
	  9 => array ("Tasu'a of Imam Hussain"),
	  10 => array ("Ashura of Imam Hussain"),
	),
	2 => array (
	  20 => array ("Arba'in of Imam Hussain"),
	  28 => array ("Demise of Prophet Muhammad", "Martyrdom of Imam Hassan (Mujtaba)"),
	  30 => array ("Martyrdom of Imam Reza"),
	),
	3 => array (
	  17 => array ("Birth of Prophet Muhammad", "Birth of Imam Jafar (Sadegh)"),
	),
	6 => array (
	  3 => array ("Martyrdom of Fatima"),
	),
	7 => array (
	  13 => array ("Birth of Imam Ali"),
	  27 => array ("Mission of Prophet Muhammad"),
	),
	8 => array (
	  15 => array ("Birth of Imam Mahdi"),
	),
	9 => array (
	  21 => array ("Martyrdom of Imam Ali"),
	),
	10 => array (
	  1 => array ("Eid of Fitr"),
	  25 => array ("Martyrdom of Imam Jafar (Sadegh)"),
	),
	12 => array (
	  10 => array ("Eid of Adha (Ghurban)"),
	  18 => array ("Eid of Ghadeer"),
	)
      );

  function is_leap_year () {
    $y = $this->y;
    return (($y%30+30)%30*11+14)%30<11;
  }
}

class IslamicCalendar_Iran extends IslamicCalendar {

  //var $month_length = array (0, 29, 29, 30, 30, 29, 30, 30, 29, 30, 30, 29, 30);
  var $month_length = array (0, 30, 30, 29, 29, 30, 29, 30, 29, /*Ramezan*/29, 30, 30, 29);
  /* Iranians typically adjust Shawwal */
  //var $leap_month = 10;
  var $base_i = 14100;

  function is_leap_year () {
    return false;
    $y = $this->y;
    return (($y%30+30)%30*11+15)%30<11;
  }

  var $holi = array (
	1 => array (
          9, # Tasu'a of Imam Hussain
          10, # Ashura of Imam Hussain
	),
	2 => array (
          20, # Arba'in of Imam Hussain
          28, # Demise of Prophet Muhammad and Martyrdom of Imam Hassan (Mujtaba)
          30, # Martyrdom of Imam Reza
	),
	3 => array (
          17, # Birth of Prophet Muhammad and Imam Jafar (Sadegh)
	),
	6 => array (
          3, # Martyrdom of Fatima
	),
	7 => array (
          13, # Birth of Imam Ali
          27, # Mission of Prophet Muhammad
	),
	8 => array (
          15, # Birth of Imam Mahdi
	),
	9 => array (
          21, # Martyrdom of Imam Ali
	),
	10 => array (
          1, # Eid of Fitr
          25, # Martyrdom of Imam Jafar (Sadegh)
	),
	12 => array (
          10, # Eid of Adha (Ghurban)
          18, # Eid of Ghadeer
        )
      );
}


/*abstract*/ class MultiCalendar extends Calendar {

  var $variation = ''; // Preferred variation
  var $calendars = array (); // Ordered list of calendar names mapped to their variations,
                             // If calendar is not string, variation is treated as
                             // calendar and a null string variation is assumed.
                             // If variation is null string, default variation
			     // is used, if it's false, none is used.
  var $formals = array (); // Names of those calendars that are formal, i.e.
                           // their holidays is regarded as holiday
  var $week_start = -1; // Will be set to the one from the first calendar if unchanged
  var $weekend = -1; // Will be set to the one from the first calendar if unchanged

  /* These are set automatically */
  var $cals = array (); // Ordered list of Calendar instances
  var $id; // Selected calendar id
  var $c; // Selected calendar instance
  var $scals = array (); // Ordered list of calendars, with selected calendar on top

  var $i = 0;

  function MultiCalendar ($variation = '', $calendars = array (), $formals = array ()) {
    if (!count ($calendars)) {
      $calendars = $this->calendars;
    }
    if (!count ($formals)) {
      $formals = $this->formals;
    }
    if (!$variation && !(false === ($j = strstr (get_class ($this), '_'))))
      $variation = ucfirst (strtolower (substr ($j, 1)));

    $this->calendars = array ();
    $this->formals = array ();
    $this->variation = $variation;

    foreach ($calendars as $calendar=>$variation) {
      if (!is_string ($calendar)) {
        $calendar = $variation;
        $variation = '';
      }
      $this->add_calendar ($calendar, $variation, !(false === array_search ($calendar, $formals)), true);
    }

    $this->select ();
    $this->synch ();

    if ($this->c) {
      if ($this->week_start < 0)
        $this->week_start = $this->c->week_start;
      if (!is_array ($this->weekend))
        $this->weekend = $this->c->weekend;
    }

    parent::Calendar ();
  }

  function add_calendar ($calendar, $variation, $formal = true, $fast = false) {
    if ($variation === '')
      $variation = $this->variation;

    /* Normalized class name */
    $klass = ucfirst (strtolower ($calendar))."Calendar";

    if ($variation && class_exists ("${klass}_${variation}"))
	$klass = "${klass}_${variation}";
    else
      $variation = false;

    if (!class_exists ($klass))
      return false;
    $instance = new $klass ();

    $this->calendars = array_merge ($this->calendars, array ($calendar=>$variation));
    $this->cals = array_merge ($this->cals, array ($calendar=>&$instance));
    if ($formal)
      $this->formals[] = $calendar;

    if (!$fast) {
      $this->select ($this->id);
      $this->synch ();
    }
  }

  function select ($id = '') {
    if (!$id)
      if ($this->id)
	return;
      else
        $id = array_shift (array_keys ($this->calendars));

    $this->id = $id;
    $this->c = &$this->cals[$id];
    $this->scals = $this->cals;
    unset ($this->scals[$id]);
    $this->scals = array_merge (array ($this->id => &$this->c), $this->scals);
  }

  function multiplex ($method, $selected_order = false) {
    $args = func_get_args ();
    array_shift ($args);
    $ret = array ();
    foreach (array_keys($selected_order ? $this->cals : $this->scals) as $id)
    {
      $cal = &$this->cals[$id];
      $ret[] = call_user_func_array (array (&$cal, $method), $args);
    }
    return $ret;
  }

  function str ($lang = '', $part = '') {
    switch ($part) {
      case '':
        $a = array (parent::str ($lang, 'w'));
        foreach ($this->scals as $id => $cal)
          $a[] = N_("Calendar|$id", $lang).": ".$cal->str ($lang);
        return implode (" \n", $a);

      default:
	return $this->c->str ($lang, $part);
    }
  }

  function is_other_holiday () {
    foreach ($this->formals as $id)
      if ($this->cals[$id]->is_other_holiday ())
	return true;
    return false;
  }

  function get_annotations () {
    return call_user_func_array ('array_merge',
				 $this->multiplex ('get_annotations', true));
  }

  function synch () {
    $this->i = $this->c->i;
    $this->multiplex ('move_to', $this->i);
    parent::synch ();
  }

  function move ($n, $what='i', $minimal_move = false) {
    if ($this->c)
      $this->c->move ($n, $what, $minimal_move);
    $this->synch ();
  }

  var $i_stack = array ();

  function save () {
    array_push ($this->i_stack, $this->i);
  }

  function restore () {
    $this->move_to (array_pop ($this->i_stack));
  }

  /* For HTML generation, we use an idea much like the decorator pattern.
     So, we chain some calls back to the selected calendar, but definitely
     the decorator pattern is not enough here, since the selected calendar
     needs to call virtual methods on us.  This is implemented (shamefully)
     using the $opts['backend'] for invoking some methods in the
     SimpleCalendar implementation :(.  */

  function html_compose_cals ($opts, $lang, $klasses, $callback_major, $callback_minor, $filler = false, $titled = true) {
    $args = func_get_args ();
    for ($i = 0; $i < 7; $i++)
      array_shift ($args);

    $use_alternate_lang = get_opt($opts, 'use_alternate_lang', $use_alternate_lang = 'minor');

    $ss = array ();
    $langs = array ();
    $ids = array ();
    $flipped = array_flip (array_keys ($this->calendars));
    foreach ($this->scals as $id=>$cal) {
      if ($cal == $this->c) {
	/* Selected calendar */
        $l = (false === array_search ($use_alternate_lang, array ('major', 'all'))) ? $lang : $cal->lang;
	$cb = $callback_major;
      } else {
	/* Alternative calendar */
        $l = (false === array_search ($use_alternate_lang, array ('minor', 'all'))) ? $lang : $cal->lang;
	$cb = $callback_minor;
      }
      if (method_exists ($cal, $cb)) {
	$callable = array ($cal, $cb);
	$extra_args = array ();
      } else {
	$callable = array ($this, $cb);
        $extra_args = array ($id);
      }
      $klass = "cal$flipped[$id]";
      $langs[$klass] = $l;
      $ids[$klass] = $id;
      $ss[$klass] = call_user_func_array ($callable, array_merge ($extra_args, array ($opts, $l, $klasses), $args));
    }

    /* Compiling output */ 
    $s = '<table cellspacing="0" cellpadding="0" class="fit"><tr>'."\n";
      /* Selected calendar goes on the top row */
      reset ($ss);
      list ($calnum, $ssitem) = each ($ss);
      array_shift ($ss);
      $this_lang = array_shift ($langs);
      $id = array_shift ($ids);
      $s .= '<td class="major '.$calnum.'"'
          . xml_lang_($this_lang, $lang)
	  . ($titled ? ' title="'.N_("Calendar|$id", $lang).'"' : '')
	  .'>';
	$s .= $ssitem;
      $s .= "</td>\n";
    /* Rest go on the second row */
    $s .= "</tr><tr>\n";
      $s .= '<td><table cellspacing="0" cellpadding="0" class="minors fit"><tr>'."\n";
      $i = 1;
      while ($ss) {
        list ($calnum, $ssitem) = each ($ss);
        array_shift ($ss);
        $this_lang = array_shift ($langs);
	$id = array_shift ($ids);
	$s .= '<td class="minor minor'.$i.' '.$calnum.'"'
            . xml_lang_($this_lang, $lang)
	    . ($titled ? ' title="'.N_("Calendar|$id", $lang).'"' : '')
	    .'>';
	$s .= $ssitem;
	$s .= "</td>\n";
	/* Put a filler? */
	if ($filler && ($ss || count ($this->cals) == 2)) {
	  $s .= '<td class="fit"></td>';
	}
	$i++;
      }
      $s .= "</tr></table></td>\n";
    $s .= "</tr></table>";
    return $s;
  }

  function html_day_text ($opts, $lang = '', $klasses = array (), $link = array ()) {
    return $this->html_compose_cals ($opts, $lang, $klasses,
	   'html_day_text', 'html_day_text', true, false, $link);
  }

  function html_day_td ($opts, $lang = '', $klasses = array (), $link = array ()) {
    /* We chain to selected calendar's method */
    $opts['backend'] = &$this;
    return $this->c->html_day_td ($opts, $lang, $klasses, $link);
  }

  function html_month_range_header_proxy ($id, $opts, $lang = '', $klasses = array ()) {
    $c = $this->c;
    $c->save ();
    $c->move_days (1 - $c->d);
    $i1 = $c->i;
    $c->move_days ($c->get_month_length () - 1);
    $i2 = $c->i;
    $c->restore ();
    $link = array ('method'=>'select', 'param'=>$id);
    return $this->cals[$id]->html_month_range_header ($opts, $lang, $klasses, $link, $i1, $i2);
  }

  function html_month_header ($opts, $lang = '', $klasses = array ()) {
    return $this->html_compose_cals ($opts, $lang, $klasses,
	   'html_month_header', 'html_month_range_header_proxy');
  }

  function html_month_body ($opts, $lang = '', $klasses = array ()) {
    /* We chain to selected calendar's method */
    $opts['backend'] = &$this;
    return $this->c->html_month_body ($opts, $lang, $klasses);
  }

  function html_month_calendar ($opts, $lang = '', $klasses = array ()) {
    /* We chain to selected calendar's method */
    $opts['backend'] = &$this;
    if ($this->c)
      return $this->c->html_month_calendar ($opts, $lang, array_merge (array ('multi_calendar'), $klasses));
  }
}

class MultiCalendar_Iran extends MultiCalendar {

  var $calendars = array ('Persian', 'Islamic', 'Gregorian');
  var $formals = array ('Persian', 'Islamic');
}




/* My gettext function, it accepts arrays too! */
function N_ ($msg, $lang = '') {
  global $messages;

  if (is_array ($msg)) {
    $GLOBALS['N_lang_ov'] = $lang;
    $a = array_map ("N_", $msg);
    unset($GLOBALS['N_lang_ov']);
    return $a;
  }

  if (!$lang && isset ($GLOBALS['N_lang_ov']))
      $lang = $GLOBALS['N_lang_ov'];

  if (!$lang && isset ($GLOBALS['lang']))
      $lang = $GLOBALS['lang'];

  if (isset($messages[$lang][$msg]))
    $msg = $messages[$lang][$msg];

  /* charset conversion layer! */
  if (is_string ($msg)) {
    if (!(false === ($j = strstr ($msg, '|'))))
      $msg = substr ($j, 1);
    $msg = htmlspecialchars ($msg);
  }

  return $msg;
}

/* And localized numbers */
function NUM_ ($num, $lang = '') {
  $digits = N_ ('0123456789', $lang);
  $s = (string) $num;
  if (!is_array($digits))
    return $s;

  $t = '';
  for ($i = 0; $i < strlen ($s); $i++)
    if (($j = strpos ('0123456789', $s[$i])) === false)
      $t .= $s[$i];
    else
      $t .= $digits[$j];
  return $t;
}

function dir_ ($lang = '') {
  $d = N_('text_dir', $lang);
  return ($d == 'text_dir') ? 'ltr' : $d;
}

function align_ ($lang = '') {
  return dir_ ($lang) == 'rtl' ? 'right' : 'left';
}

function unalign_ ($lang = '') {
  return align_ ($lang) == 'right' ? 'left' : 'right';
}

function xml_lang_ ($new_lang, $lang = '') {
  return $new_lang != $lang ? ' xml:lang="'.$new_lang.'"' : '';
}

/* And UTF-8 substring */
function substr_utf8 ($str, $start, $length = '') {
  $utf8char = '(?:[\x00-\x7F]|[\xC0-\xFF][\x80-\xBF]*)';
  $pat = "/^$utf8char{0,$start}($utf8char{0,$length})/";
  preg_match ($pat, $str, $match);
  return $match[1];
}



function make_url ($args) {
  global $_SERVER, $_REQUEST;
  if (!$args)
    return '';
  if (!is_array ($args))
    return (string)$args;

  $q = array ();
  foreach ($args as $key=>$value) {
    if (!is_array ($value))
      $q[] = urlencode($key)."=".urlencode($value);
    else
      foreach ($value as $val_key=>$val_val)
        $q[] = urlencode($key)."[$val_key]=".urlencode($val_val);
  }
  foreach ($_REQUEST as $key=>$value) {
    if (!is_array ($value))
      $q[] = urlencode($key)."=".urlencode($value);
    else
      foreach ($value as $val_key=>$val_val)
        $q[] = urlencode($key)."[$val_key]=".urlencode($val_val);
  }
  $q[] = htmlspecialchars(SID);
  return '?'.implode('&amp;', $q);
}

function make_link ($text, $args, $title = '', $klasses = array (), $new_lang = '', $lang = '') {
  if (!$args)
    return $text;
  $url = make_url ($args);
  array_unshift ($klasses, 'a');
  return '<a href="'.$url.'"'.
         ($title ? ' title="'.$title.'"' : '').
         '><span class="'.implode (' ', $klasses).'"'.xml_lang_($new_lang, $lang).'>'.
	 $text.
	 '</span></a>';
}

function &get_opt (&$opts, $item, $default) {
  if (isset ($opts[$item]))
    return $opts[$item];
  else
    return $default;
}

function write_css_color_combinations ($id, $base_klass, $klasses, $chosen = array ()) {
  if (!count ($klasses)) {
    /* choices are done. print it out */

    if (!count ($chosen))
      return;

    if (count ($chosen) == 1 && isset ($chosen[''][3]))
      $chosen[''][3] = 1;

    $colors = array (0, 0, 0);
    $total_w = 0;
    foreach ($chosen as $color) {
      if (count ($color) > 3)
	$w = $color[3];
      else
	$w = 1;
      for ($i = 0; $i < 3; $i++)
	$colors[$i] += $w * $color[$i];
      $total_w += $w;
    }
    for ($i = 0; $i < 3; $i++)
      $colors[$i] = (int)($colors[$i] / $total_w);

    if (isset ($chosen['']))
      unset ($chosen['']);
    echo sprintf("%s	{ %s: #%02X%02X%02X }\n",
        (is_string($base_klass) ? ".$base_klass" : '').
	(count($chosen) ? '.'.implode ('.', array_keys ($chosen)) : ''), $id,
	$colors[0], $colors[1], $colors[2]);

    return;
  }

  reset ($klasses);
  $klass = key ($klasses);
  $colors = current ($klasses);
  array_shift ($klasses);
  $new_chosen = array_merge ($chosen, array ($klass=>$colors));
  write_css_color_combinations ($id, $base_klass, $klasses, $new_chosen);
  if ($klass)
    write_css_color_combinations ($id, $base_klass, $klasses, $chosen);
}

/* Writes an array of color information into CSS.  The array consists of
   simple entries: a mapping from a class name to a color triple, or complex
   _to_be_blended_ entries, which are arrays themselves, containing several
   simple entries: all combinations of the classes for these simpe entries are
   enumerated and their colors are blended.  If the key for a complex entry is
   an string, that class would be prepended to all enumerated combinations.
   An optional _weight_ can be appended to the color tripels for the simple
   entries in a complex entry.  If omitted, a weight of 1 is assumed.
   Moreover, if among the single entries in a complex entry, there is an item
   with null string as key, it will be used unconditionally in the
   combinations.  If it has a zero weight however, it will be only used in the
   combination that no other class matches. */
function write_css_colors ($id, &$klasses) {
  foreach ($klasses as $klass=>$colors)
    if (is_string ($klass) && isset ($colors[0]) && !is_array ($colors[0]))
      echo sprintf(".%s	{ %s: #%02X%02X%02X }\n", $klass, $id, $colors[0], $colors[1], $colors[2]);
    else
      write_css_color_combinations ($id, $klass, $colors);
}


/* CSS Stylesheet */
function write_stylesheet ($calendar, $opts) {
  $lang = &$opts['lang'];
  ?>
<style type="text/css">

@import url('http://fonts.googleapis.com/css?family=Droid+Sans');
@import url('http://fonts.googleapis.com/earlyaccess/droidarabicnaskh.css');

body {
  font-family: 'Droid Sans', 'Droid Arabic Naskh', sans-serif;
}

/* Musts */

.calendar {
  cursor: default;
}

.fit {
  width: 100%;
}

.month_calendar td {
  text-align: center;
}

.calendar a {
  color: inherit;
  text-decoration: none;
  z-index: 0;
  cursor: default;
}

td.day {
  width: <?php echo ((int)(1000/$calendar->week_length))/10; ?>%;
}

table.day_name {
  margin: auto;
}

table.day_name td {
  width: 1.0em;
  text-align: right;
}

span.language, span.month_name {
  unicode-bidi: embed;
}

.month .minors {
  padding: 0 <?php echo @((int)(1000/(count ($calendar->calendars) - 1)))/10/5; ?>%;
}

.minor {
  font-size: 70%;
  width: <?php echo @((int)(1000/(count ($calendar->calendars) - 1)))/10; ?>%;
}

.major {
  font-size: 100%;
}

:focus {
  outline: none;
}

/* Color settings */

<?php
/* If you add/remove entries here, please search for "color class" and update there too. */
$color = array (
  'day' => array (
    ''			=> array (0, 0, 0, 0),
    'selected'		=> array (255, 255, 255, 5),
    'weekend'		=> array (155, 0, 0, 1),
    'holiday_other'	=> array (255, 0, 0, 5),
    'inactive'		=> array (220, 220, 220, 20),
  )
);
write_css_colors ('color', $color);
$background_color = array (
  'month'		=> array (236, 236, 236),
  'day' => array (
    ''			=> array (255, 255, 255, 0),
    'selected'		=> array (0, 114, 179, 5),
    'weekend'		=> array (248, 232, 232, 1),
    'annotated'		=> array (240, 248, 248, 2),
    'inactive'		=> array (255, 255, 255, 1),
  )
);
write_css_colors ('background-color', $background_color);
?>

.plain .cal0, .cal0 .header	{ color: #035 }
.plain .cal1, .cal1 .header	{ color: #5a0 }
.plain .cal2, .cal2 .header	{ color: #a50 }
/* alternative coloring, based on the position, not calendar
.plain .major, .major .header	{ color: #035 }
.plain .minor1, .minor1 .header	{ color: #5a0 }
.plain .minor2, .minor2 .header	{ color: #a50 }
*/
th.day				{ color: white }
th.day				{ background-color: #0072b3 }
.calendar			{ background-color: #f4f6f6 }
.month				{ background-color: #aaa }
.highlight_a:hover		{ background-color: #ddd }

/* Other settings */

/* Arabic script needs more room towards the bottom */
.text {
  <?php if (array_search ($lang, array ('fa', 'ar')) === false): ?>
    /* Not Arabic script */
    padding-top: 0.2ex;
    padding-bottom: 0.2ex;
  <?php else: ?>
    /* Arabic acript */
    padding-top: 0.0ex;
    padding-bottom: 0.4ex;
  <?php endif; ?>
}

body {
  margin: 0;
}

.calendar {
  text-align: center;
  padding: 0;
  margin: auto;
}

.calendar td {
  text-align: center;
}

.header {
  font-size: larger;
}

.selected.day {
  /* font-weight: inherit; */
}

.selected {
  font-weight: bold;
}

.today {
  border: 1px solid #aaa;
}

.vr { /* vertical rules, i.e. '|' */
  color: #aaa;
}

.footer {
  width: 100%;
  font-size: smaller;
}

.footer td {
  padding: 0em .4em;
}

.languages {
  font-size: smaller;
}

.buttons {
  font-size: smaller;
}

</style><?php
}


/* Translated messages and other locale info */
$messages = array ();

//*
$messages['ar'] = array ();

$messages['ar'][','] = '،';
$messages['ar']['text_dir'] = 'rtl';
$messages['ar']['0123456789'] = array('٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩');

$messages['ar']["Credits|b"] = 'ب';

$messages['ar']['English'] = 'إنجليزية';
$messages['ar']['Persian'] = 'فارسي';
$messages['ar']['Arabic'] = 'العربية';

$messages['ar']["Muharram"] = 'محرم';
$messages['ar']["Safar"] = 'صفر';
$messages['ar']["Rabi' I"] = 'ربيع الأول';
$messages['ar']["Rabi' II"] = 'ربيع الثاني';
$messages['ar']["Jumada I"] = 'جمادي الأول';
$messages['ar']["Jumada II"] = 'جمادي الثاني';
$messages['ar']["Rajab"] = 'رجب';
$messages['ar']["Sha'ban"] = 'شعبان';
$messages['ar']["Ramadan"] = 'رمضان';
$messages['ar']["Shawwal"] = 'شوّال';
$messages['ar']["Dhu al-Qi'dah"] = 'ذوالقعدة';
$messages['ar']["Dhu al-Hijjah"] = 'ذوالحجة';
// */

$messages['fa'] = array ();

$messages['fa'][','] = '،';
$messages['fa']['text_dir'] = 'rtl';
$messages['fa']['0123456789'] = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');

$messages['fa']['English'] = 'انگلیسی';
$messages['fa']['Persian'] = 'فارسی';
$messages['fa']['Arabic'] = 'عربی';

$messages['fa']['Calendar'] = 'تقویم';
$messages['fa']['Today'] = 'امروز';
$messages['fa']['Calendar|Gregorian'] = 'میلادی';
$messages['fa']['Calendar|Persian'] = 'خورشیدی';
$messages['fa']['Calendar|Islamic'] = 'قمری';
$messages['fa']['Iran'] = 'ایران';
$messages['fa']['Canada'] = 'کانادا';

$messages['fa']["Credits|b"] = 'ب';
$messages['fa']["Behdad's Calendar"] = 'تقویم بهداد';
$messages['fa']["Source code"] = 'کد منبع';
$messages['fa']["Don't forget to send me your comments!"] = 'یادتان نرود نظرات‌تان را برایم بفرستید!';
$messages['fa']["Make this start of the week"] = 'این روز را اول هفته کن';

$messages['fa']['Saturday'] = 'شنبه';
$messages['fa']['Sunday'] = 'یک‌شنبه';
$messages['fa']['Monday'] = 'دوشنبه';
$messages['fa']['Tuesday'] = 'سه‌شنبه';
$messages['fa']['Wednesday'] = 'چهارشنبه';
$messages['fa']['Thursday'] = 'پنج‌شنبه';
$messages['fa']['Friday'] = 'جمعه';

$messages['fa']['Farvardin'] = 'فروردین';
$messages['fa']['Ordibehesht'] = 'اردیبهشت';
$messages['fa']['Khordad'] = 'خرداد';
$messages['fa']['Tir'] = 'تیر';
$messages['fa']['Mordad'] = 'مرداد';
$messages['fa']['Shahrivar'] = 'شهریور';
$messages['fa']['Mehr'] = 'مهر';
$messages['fa']['Aban'] = 'آبان';
$messages['fa']['Azar'] = 'آذر';
$messages['fa']['Dey'] = 'دی';
$messages['fa']['Bahman'] = 'بهمن';
$messages['fa']['Esfand'] = 'اسفند';

$messages['fa']['January'] = 'ژانویه';
$messages['fa']['February'] = 'فوریه';
$messages['fa']['March'] = 'مارس';
$messages['fa']['April'] = 'آوریل';
$messages['fa']['May'] = 'مه';
$messages['fa']['June'] = 'ژوئن';
$messages['fa']['July'] = 'ژوئیه';
$messages['fa']['August'] = 'اوت';
$messages['fa']['September'] = 'سپتامبر';
$messages['fa']['October'] = 'اکتبر';
$messages['fa']['November'] = 'نوامبر';
$messages['fa']['December'] = 'دسامبر';

$messages['fa']["Muharram"] = 'محرم';
$messages['fa']["Safar"] = 'صفر';
$messages['fa']["Rabi' I"] = 'ربیع‌الاول';
$messages['fa']["Rabi' II"] = 'ربیع‌الثانی';
$messages['fa']["Jumada I"] = 'جمادی‌الاول';
$messages['fa']["Jumada II"] = 'جمادی‌الثانی';
$messages['fa']["Rajab"] = 'رجب';
$messages['fa']["Sha'ban"] = 'شعبان';
$messages['fa']["Ramadan"] = 'رمضان';
$messages['fa']["Shawwal"] = 'شوال';
$messages['fa']["Dhu al-Qi'dah"] = 'ذیقعده';
$messages['fa']["Dhu al-Hijjah"] = 'ذیحجه';

$messages['fa']["Christmas"] = 'عید کریسمس';

$messages['fa']["Nowrooz"] = 'عید نوروز';
$messages['fa']["Islamic Republic Day"] = 'روز جمهوری اسلامی ایران';
$messages['fa']["Nature Day"] = 'روز طبیعت';
$messages['fa']["Demise of Imam Khomeini"] = 'رحلت امام خمینی (ره)';
$messages['fa']["Revolt of 15 Khordad"] = 'قیام پانزده خرداد';
$messages['fa']["Victory of Revolution of Iran"] = 'پیروزی انقلاب اسلامی';
$messages['fa']["Nationalization of Oil Industry"] = 'روز ملی شدن صنعت نفت';

$messages['fa']["Tasu'a of Imam Hussain"] = 'تاسوعای حسینی';
$messages['fa']["Ashura of Imam Hussain"] = 'عاشورای حسینی';
$messages['fa']["Arba'in of Imam Hussain"] = 'اربعین حسینی';
$messages['fa']["Demise of Prophet Muhammad"] = 'رحلت رسول اکرم (ص)';
$messages['fa']["Martyrdom of Imam Hassan (Mujtaba)"] = 'شهادت امام حسن مجتبی (ع)';
$messages['fa']["Martyrdom of Imam Reza"] = 'شهادت امام رضا (ع)';
$messages['fa']["Birth of Prophet Muhammad"] = 'ولادت رسول اکرم (ص)';
$messages['fa']["Birth of Imam Jafar (Sadegh)"] = 'ولادت امام جعفر صادق (ع)';
$messages['fa']["Martyrdom of Fatima"] = 'شهادت حضرت فاطمه (س)';
$messages['fa']["Birth of Imam Ali"] = 'ولادت حضرت علی (ع)';
$messages['fa']["Mission of Prophet Muhammad"] = 'مبعث رسول اکرم (ص)';
$messages['fa']["Birth of Imam Mahdi"] = 'ولادت حضرت مهدی (عج)';
$messages['fa']["Martyrdom of Imam Ali"] = 'شهادت حضرت علی (ع)';
$messages['fa']["Eid of Fitr"] = 'عید سعید فطر';
$messages['fa']["Martyrdom of Imam Jafar (Sadegh)"] = 'شهادت امام جعفر صادق (ع)';
$messages['fa']["Eid of Adha (Ghurban)"] = 'عید قربان';
$messages['fa']["Eid of Ghadeer"] = 'عید سعید غدیر خم';






/* Settings */

$title = "Behdad's Calendar";
$default_langs = array (/*'ar'=>'Arabic', */'en'=>'English', 'fa'=>'Persian');
$default_lang = 'fa';
$lang_range = array_keys ($default_langs);
$default_calendar = "MultiCalendar_Iran";
$default_opts = array (
			'lang' => $default_lang,
			'langs' => $default_langs,
			'cellspacing' => '1',
			'use_alternate_lang' => 'none', /* can be 'none', 'minor', 'major', 'all' */
			'fit_rows' => true,
			'highlight_today' => true,
		      );


function initialize ($default_calendar, $default_opts, $prefix = '') {
  global $_SESSION;
  $_SESSION["${prefix}calendar"] = new $default_calendar ();
  $_SESSION["${prefix}opts"] = $default_opts;
  $_SESSION["${prefix}calendar"]->move_today ();
  $s = stat (__FILE__);
  $_SESSION["${prefix}timestamp"] = $s['mtime'];
}


function reload_calendar (&$calendar) {
  $i = $calendar->i;
  $c = get_class ($calendar);
  $calendar = null;
  $calendar = new $c ();
  $calendar->move_to ($i);
}

function init_session ($default_calendar, $default_opts, $prefix = '') {
  global $_SERVER, $_SESSION;

  if ($_SERVER['PHP_SELF'])
    session_start ();
  if (!isset ($_SESSION["${prefix}calendar"]))
    initialize ($default_calendar, $default_opts, $prefix);

  /* See if we need to rebuild the object */
  /* We do that if the code is more recent than the object */
  $s = stat (__FILE__);
  if ($_SESSION["${prefix}timestamp"] < $s['mtime']) {
    $calendar = &$_SESSION["${prefix}calendar"];
    reload_calendar ($calendar);
    $_SESSION["${prefix}timestamp"] = $s['mtime'];
  }
  unset ($stat);

  $GLOBALS["${prefix}calendar"] = &$_SESSION["${prefix}calendar"];
  $GLOBALS["${prefix}opts"] = &$_SESSION["${prefix}opts"];
  $GLOBALS["${prefix}lang"] = &$GLOBALS["${prefix}opts"]['lang'];
}

function process_request (&$calendar, &$opts, $prefix = '') {
  global $_REQUEST, $_SERVER;

  /* Check some requests */
  if (isset ($_REQUEST["${prefix}action"])) {
    $action = $_REQUEST["${prefix}action"];
    unset ($_REQUEST["${prefix}action"]);
    switch ($action) {
      case 'reload':
	reload_calendar ($calendar);
	break;
      case 'reset':
	session_destroy ();
	break;
    }
  }

  /* Set requested options */
  if (isset ($_REQUEST["${prefix}option"])) {
    $options = $_REQUEST["${prefix}option"];
    unset ($_REQUEST["${prefix}option"]);
    if (isset($_REQUEST["${prefix}value"])) {
      $values = $_REQUEST["${prefix}value"];
      unset ($_REQUEST["${prefix}value"]);
	}
    if (!is_array ($options)) {
      $options = array ($options);
      if (isset ($values))
	    $values = array ($values);
    }

    foreach ($options as $key=>$option)
      if (isset ($values)) {
    	$value = $values[$key];
    	if ($value == 'default') {
    	  $d = "default_$option";
    	  if (isset ($GLOBALS[$d]))
    	    $value = $GLOBALS[$d];
    	}
    	$d = "${option}_range";
    	if (isset ($GLOBALS[$d]) && false === array_search ($value, $GLOBALS[$d]))
    	  continue;
    	$opts[$option] = $value;
      } else {
    	unset ($opts[$option]);
      }
  }

  /* Run requested method */
  if (isset ($_REQUEST["${prefix}method"])) {
    $methods = $_REQUEST["${prefix}method"];
    unset ($_REQUEST["${prefix}method"]);
    if (isset($_REQUEST["${prefix}param"])) {
      $params = $_REQUEST["${prefix}param"];
      unset ($_REQUEST["${prefix}param"]);
	}
    if (!is_array ($methods)) {
      $methods = array ($methods);
      if (isset ($params))
	$params = array ($params);
    }

    foreach ($methods as $key=>$method) {
      if (!method_exists ($calendar, $method))
	return;
      if (isset ($params)) {
	$param = $params[$key];
	@$calendar->$method ($param);
      } else {
	@$calendar->$method ();
      }
    }
  }
}

function write_calendar ($calendar, $opts) {
  global $_SERVER;

  define ('VR', '&nbsp;<span class="vr">|</span>&nbsp;');
  $lang = &$opts['lang'];
  $langs = get_opt($opts, 'langs', $langs = false);

  /* Main dialog */
  echo '<div class="calendar" style="direction: '.dir_($lang).'">';

  /* Month calendar widget */
  echo $calendar->html_month_calendar ($opts, $lang);

  /* Buttons */
  $bs = array ();
  /* Today button */
  $i = $calendar->i;
  $calendar->save ();
  $klasses = array ('button', 'button_today');
  $calendar->move_today ();
  if ($i == $calendar->i)
    $klasses[] = 'selected';
  $bs[] = make_link (N_("Today"),
                     array ('method'=>'move_today'),
                     $calendar->str (),
		     $klasses);
  $calendar->restore ();
  /* Source code button */
  if (isset($_SERVER['SCRIPT_FILENAME']) &&
      realpath($_SERVER['SCRIPT_FILENAME']) == __FILE__ &&
      file_exists (__FILE__.'s')) {
    $bs[] = make_link (N_("Source|+"),
		       $_SERVER["SCRIPT_NAME"].'s',
                       N_("Source code"),
		       array ('button'));
  }
  /* Credits button */
  $bs[] = make_link (N_("Credits|b"),
                     "http://behdad.org/",
                     N_("Don't forget to send me your comments!"),
		     array ('button', 'button_credits'));

  echo '<table cellspacing="0" cellpadding="0" class="footer"><tr>';
  echo '<td class="buttons text">'.implode (VR, $bs).'</td>';
  echo '<td class="fit"></td>';

  /* Language options */
  if ($langs) {
    $ls = array ();
    foreach ($langs as $l=>$name) {
      $s = N_($name, $l);
      $s = substr_utf8 ($s, 0, 2);
      $klasses = array ('language');
      if ($l == $lang)
	$klasses[] = 'selected';
      $s = make_link ($s, array ('option'=>'lang', 'value'=>$l), N_($name), $klasses, $l, $lang);
      $ls[] = $s;
    }
    echo '<td class="languages text">'.implode (VR, $ls).'</td>';
  }

  /* End of footer */
  echo '</tr></table>';

  /* End of dialog */
  echo '</div>';
}




if ((isset($draw_calendar) && $draw_calendar) ||
    !isset($_SERVER['SCRIPT_FILENAME'])  /* PHP 4 cli */ ||
    $_SERVER['SCRIPT_FILENAME'] == $_SERVER['SCRIPT_NAME'] /* PHP 5 cli */ ||
    realpath($_SERVER['SCRIPT_FILENAME']) == __FILE__ /* called directly from web, not included */):


init_session ($default_calendar, $default_opts);
process_request ($calendar, $opts);


/* Here starts the response */

/* Headers */

/* Disable caching */ 
// Date in the past
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");
// always modified
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
// HTTP/1.1
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Cache-Control: post-check=0, pre-check=0", false);
// HTTP/1.0
header("Pragma: no-cache");



/* (Compressed) Body */
if (!ini_get("zlib.output_compression") && ini_get("output_handler") != "ob_gzhandler")
  ob_start("ob_gzhandler");


$embed = isset ($_REQUEST['embed']) || isset ($_REQUEST['synd']) || isset ($_REQUEST['container']);

?>
<<?php echo '?'; ?>xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
<html xmlns="http://www.w3.org/1999/xhtml"
      <?php echo xml_lang_($lang); ?> dir="<?php echo dir_($lang); ?>">
<head>
  <meta http-equiv="Content-Type" content="application/xhtml+xml; charset=utf-8"/>
  <meta http-equiv="Content-Style-Type" content="text/css"/>
  <meta http-equiv="Content-Language" content="<?php echo $lang; ?>"/>
  <meta http-equiv="Last-Modified" content="<?php echo gmdate("D, d M Y H:i:s"); ?> GMT"/>
<?php
    /* Some search-engine bot controlling is in place here.  We set nofollow
     * no matter what, but also set noindex if there are any arguments passed
     * in.  This way, hopefully, we get the front page of calendar indexed,
     * but not any specific dates, etc. */
    $robot_content = "nofollow noarchive";
    if ($_SERVER['QUERY_STRING'])
      $robot_content .= " noindex";
    foreach (array ("robots", "Googlebot", "msnbot") as $robot)
      echo '  <meta name="'.$robot.'" content="'.$robot_content.'"/>'."\n";
  ?>
  <meta name="webmaster" content="http://behdad.org/"/>
  <meta xml:lang="en" name="author" content="Behdad Esfahbod"/>
  <meta xml:lang="en" name="copyright" content="2005--2010  Behdad Esfahbod"/>
  <meta xml:lang="en" name="description" content="This is a multi-system calendar widget prototype, that currently supports the Iranian calendar: Persian, Islamic, and Gregorian systems."/>
  <title><?php echo N_($title); ?></title>
<?php write_stylesheet ($calendar, $opts); ?>
<?php if (!$embed): ?>
<style type="text/css">
.calendar {
  font-size: 120%;
  width: <?php echo 1 + (count ($calendar->cals) > 1 ? 3 : 2) * $calendar->week_length; ?>em;
  border: solid 2px #aaa;
}
</style>
<?php endif; ?>
<?php if ($embed && isset($_REQUEST['container']) && $_REQUEST['container'] == 'gm'): ?>
<style type="text/css">
/* Gmail has narrow columns */
.calendar {
  font-size: 70%;
}
</style>
<?php endif; ?>
</head>
<body>
<?php

/* Google Gadget support */
if (isset ($_REQUEST['libs'])) {
    $libs = $_REQUEST['libs'];
    if (strpos ($libs, ':') > 0) {
	//$libs = explode (':', $_REQUEST['libs']);
	//foreach ($libs as $lib)
	//	    echo '<script type="text/javascript" src="http://www.google.com/ig/f/behdadcalendar/lib/lib'.$lib.'.js"></script>'."\n";
    } else {
	$libs = explode (',', $_REQUEST['libs']);
	foreach ($libs as $lib)
		    echo '<script type="text/javascript" src="http://www.google.com/ig/f/'.$lib.'"></script>'."\n";
	echo '<script type="text/javascript">_IG_AdjustIFrameHeight();</script>';
    }
}

write_calendar ($calendar, $opts);
?>

<?php if (!$embed): ?>
<p style="text-align: center; direction:ltr;">
<a href="?embed=1">Version for iframe embedding</a>
</p>
<?php
endif;
?>
</body>
</html>
<?php endif; ?>
