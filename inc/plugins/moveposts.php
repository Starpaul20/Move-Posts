<?php
/**
 * Move Posts
 * Copyright 2011 Starpaul20
 */

// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

// Tell MyBB when to run the hooks
$plugins->add_hook("moderation_start", "moveposts_run");
$plugins->add_hook("showthread_start", "moveposts_lang");

// The information that shows up on the plugin manager
function moveposts_info()
{
	return array(
		"name"				=> "Move Posts",
		"description"		=> "Allows moderators to move posts from one thread to another.",
		"website"			=> "http://galaxiesrealm.com/index.php",
		"author"			=> "Starpaul20",
		"authorsite"		=> "http://galaxiesrealm.com/index.php",
		"version"			=> "1.1",
		"guid"				=> "c8142214157b9470bdad92ad24658683",
		"compatibility"		=> "16*"
	);
}

// This function runs when the plugin is activated.
function moveposts_activate()
{
	global $db;
	$insert_array = array(
		'title'		=> 'moderation_inline_moveposts',
		'template'	=> $db->escape_string('<html>
<head>
<title>{$mybb->settings[\'bbname\']} - {$lang->move_posts}</title>
{$headerinclude}
</head>
<body>
{$header}
<form action="moderation.php" method="post">
<input type="hidden" name="my_post_key" value="{$mybb->post_code}" />
<table border="0" cellspacing="{$theme[\'borderwidth\']}" cellpadding="{$theme[\'tablespace\']}" class="tborder">
<tr>
<td class="thead" colspan="2"><strong>{$lang->move_posts}</strong></td>
</tr>
<tr>
<td class="tcat" colspan="2"><span class="smalltext"><strong>{$lang->move_posts_info}</strong></span></td>
</tr>
{$loginbox}
<tr>
<td class="trow2"><strong>{$lang->thread_to_move_posts}</strong><br /><span class="smalltext">{$lang->move_post_note}</span></td>
<td class="trow2" width="60%"><input type="text" class="textbox" name="threadurl" size="40" />
</tr>
</table>
<br />
<div align="center"><input type="submit" class="button" name="submit" value="{$lang->move_posts}" /></div>
<input type="hidden" name="action" value="do_multimoveposts" />
<input type="hidden" name="tid" value="{$tid}" />
<input type="hidden" name="posts" value="{$inlineids}" />
</form>
{$footer}
</body>
</html>'),
		'sid'		=> '-1',
		'version'	=> '',
		'dateline'	=> TIME_NOW
	);
	$db->insert_query("templates", $insert_array);

	include MYBB_ROOT."/inc/adminfunctions_templates.php";
	find_replace_templatesets("showthread_inlinemoderation", "#".preg_quote('</optgroup>')."#i", '<option value="multimoveposts">{$lang->move_posts}</option></optgroup>');
}

// This function runs when the plugin is deactivated.
function moveposts_deactivate()
{
	global $db;
	$db->delete_query("templates", "title IN('moderation_inline_moveposts')");

	include MYBB_ROOT."/inc/adminfunctions_templates.php";
	find_replace_templatesets("showthread_inlinemoderation", "#".preg_quote('<option value="multimoveposts">{$lang->move_posts}</option>')."#i", '', 0);
}

// Move posts - Inline moderation tool
function moveposts_run()
{
	global $db, $mybb, $lang, $templates, $theme, $headerinclude, $header, $footer, $loginbox, $inlineids;
	$lang->load("moveposts");

	if($mybb->input['action'] != "multimoveposts" && $mybb->input['action'] != "do_multimoveposts")
	{
		return;
	}

	if($mybb->user['uid'] != 0)
	{
		eval("\$loginbox = \"".$templates->get("changeuserbox")."\";");
	}
	else
	{
		eval("\$loginbox = \"".$templates->get("loginbox")."\";");
	}

	$tid = intval($mybb->input['tid']);
	$thread = get_thread($tid);

	if($mybb->input['action'] == "multimoveposts" && $mybb->request_method == "post")
	{
		// Verify incoming POST request
		verify_post_check($mybb->input['my_post_key']);

		build_forum_breadcrumb($thread['fid']);
		add_breadcrumb($thread['subject'], get_thread_link($thread['tid']));
		add_breadcrumb($lang->nav_multi_moveposts);

		if($mybb->input['inlinetype'] == 'search')
		{
			$posts = getids($mybb->input['searchid'], 'search');
		}
		else
		{
			$posts = getids($tid, 'thread');
		}

		if(count($posts) < 1)
		{
			error($lang->error_inline_nopostsselected);
		}
		
		if(!is_moderator_by_pids($posts, "canmanagethreads"))
		{
			error_no_permission();
		}
		$posts = array_map('intval', $posts);
		$pidin = implode(',', $posts);

		// Make sure that we are not moving posts in a thread with one post
		// Select number of posts in each thread that the moved post is in
		$query = $db->query("
			SELECT DISTINCT p.tid, COUNT(q.pid) as count
			FROM ".TABLE_PREFIX."posts p
			LEFT JOIN ".TABLE_PREFIX."posts q ON (p.tid=q.tid)
			WHERE p.pid IN ($pidin)
			GROUP BY p.tid, p.pid
		");
		$threads = $pcheck = array();
		while($tcheck = $db->fetch_array($query))
		{
			if(intval($tcheck['count']) <= 1)
			{
				error($lang->error_cantmoveonepost);
			}
			$threads[] = $pcheck[] = $tcheck['tid']; // Save tids for below
		}

		// Make sure that we are not moving all posts in the thread
		// The query does not return a row when the count is 0, so find if some threads are missing (i.e. 0 posts after move)
		$query = $db->query("
			SELECT DISTINCT p.tid, COUNT(q.pid) as count
			FROM ".TABLE_PREFIX."posts p
			LEFT JOIN ".TABLE_PREFIX."posts q ON (p.tid=q.tid)
			WHERE p.pid IN ($pidin) AND q.pid NOT IN ($pidin)
			GROUP BY p.tid, p.pid
		");
		$pcheck2 = array();
		while($tcheck = $db->fetch_array($query))
		{
			if($tcheck['count'] > 0)
			{
				$pcheck2[] = $tcheck['tid'];
			}
		}
		if(count($pcheck2) != count($pcheck))
		{
			// One or more threads do not have posts after moving
			error($lang->error_cantmoveall);
		}

		$inlineids = implode("|", $posts);
		if($mybb->input['inlinetype'] == 'search')
		{
			clearinline($mybb->input['searchid'], 'search');
		}
		else
		{
			clearinline($tid, 'thread');
		}

		eval("\$multimove = \"".$templates->get("moderation_inline_moveposts")."\";");
		output_page($multimove);
	}

	if($mybb->input['action'] == "do_multimoveposts" && $mybb->request_method == "post")
	{
		// Verify incoming POST request
		verify_post_check($mybb->input['my_post_key']);

		$postlist = explode("|", $mybb->input['posts']);
		foreach($postlist as $pid)
		{
			$pid = intval($pid);
			$plist[] = $pid;
		}

		if(!is_moderator_by_pids($plist, "canmanagethreads"))
		{
			error_no_permission();
		}

		// Google SEO URL support
		if($db->table_exists("google_seo"))
		{
			// Build regexp to match URL.
			$regexp = "{$mybb->settings['bburl']}/{$mybb->settings['google_seo_url_threads']}";

			if($regexp)
			{
				$regexp = preg_quote($regexp, '#');
				$regexp = str_replace('\\{\\$url\\}', '([^./]+)', $regexp);
				$regexp = str_replace('\\{url\\}', '([^./]+)', $regexp);
				$regexp = "#^{$regexp}$#u";
			}

			// Fetch the (presumably) Google SEO URL:
			$url = $mybb->input['threadurl'];

			// $url can be either 'http://host/Thread-foobar' or just 'foobar'.

			// Kill anchors and parameters.
			$url = preg_replace('/^([^#?]*)[#?].*$/u', '\\1', $url);

			// Extract the name part of the URL.
			$url = preg_replace($regexp, '\\1', $url);

			// Unquote the URL.
			$url = urldecode($url);

			// If $url was 'http://host/Thread-foobar', it is just 'foobar' now.

			// Look up the ID for this item.
			$query = $db->simple_select("google_seo", "id", "idtype='4' AND url='".$db->escape_string($url)."'");
			$movetid = $db->fetch_field($query, 'id');
		}

		// explode at # sign in a url (indicates a name reference) and reassign to the url
		$realurl = explode("#", $mybb->input['threadurl']);
		$mybb->input['threadurl'] = $realurl[0];

		// Are we using an SEO URL?
		if(substr($mybb->input['threadurl'], -4) == "html")
		{
			// Get thread to move tid the SEO way
			preg_match("#thread-([0-9]+)?#i", $mybb->input['threadurl'], $threadmatch);
			preg_match("#post-([0-9]+)?#i", $mybb->input['threadurl'], $postmatch);
			
			if($threadmatch[1])
			{
				$parameters['tid'] = $threadmatch[1];
			}
			
			if($postmatch[1])
			{
				$parameters['pid'] = $postmatch[1];
			}
		}
		else
		{
			// Get thread to move tid the normal way
			$splitloc = explode(".php", $mybb->input['threadurl']);
			$temp = explode("&", my_substr($splitloc[1], 1));

			if(!empty($temp))
			{
				for($i = 0; $i < count($temp); $i++)
				{
					$temp2 = explode("=", $temp[$i], 2);
					$parameters[$temp2[0]] = $temp2[1];
				}
			}
			else
			{
				$temp2 = explode("=", $splitloc[1], 2);
				$parameters[$temp2[0]] = $temp2[1];
			}
		}

		if($parameters['pid'] && !$parameters['tid'])
		{
			$query = $db->simple_select("posts", "*", "pid='".intval($parameters['pid'])."'");
			$post = $db->fetch_array($query);
			$movetid = $post['tid'];
		}
		elseif($parameters['tid'])
		{
			$movetid = $parameters['tid'];
		}
		$movetid = intval($movetid);
		$query = $db->simple_select("threads", "*", "tid='".intval($movetid)."'");
		$movethread = $db->fetch_array($query);
		if(!$movethread['tid'])
		{
			error($lang->error_badmoveurl);
		}
		if($movetid == $tid)
		{ // sanity check
			error($lang->error_movetoself);
		}

		move_posts($plist, $movetid);

		$pid_list = implode(', ', $plist);
		$lang->moved_selective_posts = $lang->sprintf($lang->moved_selective_posts, $pid_list, $movetid);

		log_moderator_action(array("tid" => $tid, "fid" => $thread['fid']), $lang->moved_selective_posts);

		moderation_redirect(get_thread_link($tid), $lang->redirect_postsmoved);
	}
	exit;
}

// Show language on show thread
function moveposts_lang()
{
	global $lang;
	$lang->load("moveposts");
}

/**
 * Move posts from one thread to another
 *
 * @param array Post IDs to be moved
 * @param int New thread ID
 * @return boolean true
 */
function move_posts($pids, $movetid)
{
	global $db, $thread;

	$movetid = intval($movetid);
	$newtid = get_thread($movetid);

	// Make sure we only have valid values
	$pids = array_map('intval', $pids);
	$pids_list = implode(',', $pids);

	// Get forum infos
	$query = $db->simple_select("forums", "fid, usepostcounts, posts, unapprovedposts");
	while($forum = $db->fetch_array($query))
	{
		$forum_cache[$forum['fid']] = $forum;
	}

	// Get selected posts before moving forums to keep old fid
	$original_posts_query = $db->query("
		SELECT p.pid, p.tid, p.fid, p.visible, p.uid, t.visible as threadvisible, t.replies as threadreplies, t.unapprovedposts as threadunapprovedposts, t.attachmentcount as threadattachmentcount, COUNT(a.aid) as postattachmentcount
		FROM ".TABLE_PREFIX."posts p
		LEFT JOIN ".TABLE_PREFIX."threads t ON (p.tid=t.tid)
		LEFT JOIN ".TABLE_PREFIX."attachments a ON (a.pid=p.pid)
		WHERE p.pid IN ($pids_list)
		GROUP BY p.pid, p.tid, p.fid, p.visible, p.uid, t.visible, t.replies, t.unapprovedposts,t.attachmentcount
	");

	// Move the selected posts over
	$sqlarray = array(
		"tid" => $newtid['tid'],
		"fid" => $newtid['fid'],
		"replyto" => 0
	);
	$db->update_query("posts", $sqlarray, "pid IN ($pids_list)");

	// Get posts being moved
	while($post = $db->fetch_array($original_posts_query))
	{
		if($post['visible'] == 1)
		{
			// Modify users' post counts
			if($forum_cache[$post['fid']]['usepostcounts'] == 1 && $forum_cache[$newtid['fid']]['usepostcounts'] == 0)
			{
				// Moving into a forum that doesn't count post counts
				if(!isset($user_counters[$post['uid']]))
				{
					$user_counters[$post['uid']] = 0;
				}
				--$user_counters[$post['uid']];
			}
			elseif($forum_cache[$post['fid']]['usepostcounts'] == 0 && $forum_cache[$newtid['fid']]['usepostcounts'] == 1)
			{
				// Moving into a forum that does count post counts
				if(!isset($user_counters[$post['uid']]))
				{
					$user_counters[$post['uid']] = 0;
				}
				++$user_counters[$post['uid']];
			}

			// Subtract 1 from the old thread's replies
			if(!isset($thread_counters[$post['tid']]['replies']))
			{
				$thread_counters[$post['tid']]['replies'] = $post['threadreplies'];
			}
			--$thread_counters[$post['tid']]['replies'];
			// Add 1 to the new thread's replies
			if(!isset($thread_counters[$newtid['tid']]['replies']))
			{
				$thread_counters[$newtid['tid']]['replies'] = $newtid['replies'];
			}
			++$thread_counters[$newtid['tid']]['replies'];

			if($newtid['fid'] != $post['fid'])
			{
				// Only need to change forum info if the old forum is different from new forum
				// Subtract 1 from the old forum's posts
				if(!isset($forum_counters[$post['fid']]['posts']))
				{
					$forum_counters[$post['fid']]['posts'] = $forum_cache[$post['fid']]['posts'];
				}
				--$forum_counters[$post['fid']]['posts'];
				// Add 1 to the new forum's posts
				if(!isset($forum_counters[$newtid['fid']]['posts']))
				{
					$forum_counters[$newtid['fid']]['posts'] = $forum_cache[$newtid['fid']]['posts'];
				}
				++$forum_counters[$newtid['fid']]['posts'];
			}

		}
		elseif($post['visible'] == 0)
		{
			// Unapproved post
			// Subtract 1 from the old thread's unapproved posts
			if(!isset($thread_counters[$post['tid']]['unapprovedposts']))
			{
				$thread_counters[$post['tid']]['unapprovedposts'] = $post['threadunapprovedposts'];
			}
			--$thread_counters[$post['tid']]['unapprovedposts'];
			// Add 1 to the new thread's unapproved posts
			if(!isset($thread_counters[$newtid['tid']]['unapprovedposts']))
			{
				$thread_counters[$newtid['tid']]['unapprovedposts'] = $newtid['unapprovedposts'];
			}
			++$thread_counters[$newtid['tid']]['unapprovedposts'];

			if($newtid['fid'] != $post['fid'])
			{
				// Only need to change forum info if the old forum is different from new forum
				// Subtract 1 from the old forum's unapproved posts
				if(!isset($forum_counters[$post['fid']]['unapprovedposts']))
				{
					$forum_counters[$post['fid']]['unapprovedposts'] = $forum_cache[$post['fid']]['unapprovedposts'];
				}
				--$forum_counters[$post['fid']]['unapprovedposts'];
				// Add 1 to the new forum's unapproved posts
				if(!isset($forum_counters[$newtid['fid']]['unapprovedposts']))
				{
					$forum_counters[$newtid['fid']]['unapprovedposts'] = $forum_cache[$newtid['fid']]['unapprovedposts'];
				}
				++$forum_counters[$newtid['fid']]['unapprovedposts'];
			}
		}

		// Subtract attachment counts from old thread and add to new thread (which are counted regardless of post or attachment unapproval at time of coding)
		if(!isset($thread_counters[$post['tid']]['attachmentcount']))
		{
			$thread_counters[$post['tid']]['attachmentcount'] = $post['threadattachmentcount'];
		}
		$thread_counters[$post['tid']]['attachmentcount'] -= $post['postattachmentcount'];
		$thread_counters[$newtid['tid']]['attachmentcount'] += $post['postattachmentcount'];
	}

	// Update user post counts
	if(is_array($user_counters))
	{
		foreach($user_counters as $uid => $change)
		{
			if($change >= 0)
			{
				$change = '+'.$change; // add the addition operator for query
			}
			$db->update_query("users", array("postnum" => "postnum{$change}"), "uid='{$uid}'", 1, true);
		}
	}

	// Update thread counters
	if(is_array($thread_counters))
	{
		foreach($thread_counters as $tid => $counters)
		{
			$db->update_query("threads", $counters, "tid='{$tid}'");

			update_thread_data($tid);

			// Update first post columns
			update_first_post($tid);
		}
	}

	// Update forum counters
	if(is_array($forum_counters))
	{
		foreach($forum_counters as $fid => $counters)
		{
			update_forum_counters($fid, $counters);
		}
	}

	update_thread_data($newtid['tid']);
	update_first_post($newtid['tid']);

	return true;
}

?>