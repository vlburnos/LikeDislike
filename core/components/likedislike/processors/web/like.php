<?php
/**
 * LikeDislike
 */
        
$likedislike = $modx->getService('likedislike','likeDislike',$modx->getOption('likedislike.core_path',null,$modx->getOption('core_path').'components/likedislike/').'model/likedislike/',$scriptProperties);
if (!($likedislike instanceof likeDislike)) return ' no conect likeDislike';

// Only respond to requests for Ajax
if(! $likedislike->is_ajax())
    return json_encode(array('error' => 'ajax_error'));

// Immediately get out of here if no valid vote was cast.
// All required POST keys must be present.
if ( ! isset($_POST['likedislike_id']) OR ! isset($_POST['likedislike_vote']) OR ! isset($_POST['likedislike_format']))
    return FALSE;

// Has somebody been messing with the form?
// Well, we won't let them mess with us!
if ( ! preg_match('/^[0-9]++$/D', (string) $_POST['likedislike_id']) OR ! is_string($format = $_POST['likedislike_format']))
    return FALSE;

// Clean form input
$id   = (int) $_POST['likedislike_id'];
$vote = (int) $_POST['likedislike_vote'];
$round = $_POST['likedislike_round'] ? (int) $_POST['likedislike_round'] : 0;

// Is the current user blocked by IP?
if ($likedislike->ip_blocked($likedislike->get_ip())){
    $error = 'ip_blocked';
}

// Attempt to load the relevant LikrDislike item.
// If the item doesn't exist, the id is invalid.
elseif ( ! $item = $likedislike->load_item((int)$_POST['likedislike_id'])){
    $error = 'invalid_id';
}

// Voting on the item has been closed
elseif ($item['closed']){
    $error = 'closed';
}

// The user has already voted on this item
elseif ($item['user_voted']){
    $error = 'already_voted';
}

// You have to be logged in to vote
elseif ($likedislike->options('user_login_required') AND ! $this->modx->user->isAuthenticated($modx->context->get('key'))){
    $error = 'login_required';
}

// All checks passed, yay!
if (empty($error)){
    // Update the vote count in the items table, and recalculate the vote results
    
    // Vote value must be either 0 or 1
    $vote = min(1, max(0, (int) $vote));
    
    if ($vote){
        // Add an "up" vote
        $item['votes_up']++;
        $sql = 'votes_up';
    }else{
        // Add a "down" vote
        $item['votes_down']++;
        $sql = 'votes_down';
    }
    
    // Recalculate the vote results, no need to reload the item from database
    $item = array_merge ($item, $likedislike->calculate_votes($item['votes_up'],$item['votes_down']));
    
    // Update the item record
    $sth = $modx->getObject('LikedislikeItems',array('id' => $item['id']));
    $sth->set($sql,$item[$sql]);
    
    if(!$sth->save()) {
        if ($likedislike->options('debug')) $modx->log(modX::LOG_LEVEL_ERROR, "I can not create an entry for obgect LikedislikeItems - item ".$item['id']);
    }
    
    
    // The current user has just cast a vote
    $item['user_voted'] = TRUE;
    
    // Add the item id to a cookie
    if ($likedislike->options('cookie_check')){
        $likedislike->add_id_cookie($item['id']);
    }
    
    // Combine the storage of the IP and user id into one query for optimization
    $ip = ($likedislike->options('ip_check')) ? $likedislike->get_ip() : NULL;
    $user_id = ($likedislike->options('user_id_check')) ? $this->modx->user->get('id') : NULL;
    
    if ($ip OR $user_id){
        $sth = $modx->newObject('LikedislikeVotes');
        $sth->set('item_id',$item['id']);
        $sth->set('ip',$ip);
        $sth->set('user_id',$user_id);
        $sth->set('value',$vote);
        $sth->set('date',time());
        if(!$sth->save()){
            if ($likedislike->options('debug')) $modx->log(modX::LOG_LEVEL_ERROR, "I can not create an entry for object LikedislikeVotes - user_id ".$user_id);
        }        
    }
}

// Send the item back in JSON format
header('Content-Type: application/json; charset=utf-8');

if ( ! empty($error)){
    // Send back the error
    return json_encode(array('error' => $error));
}else{    
    // Format the result using the same format the item was created with
    $item = array_merge ($item, $likedislike->format_item($format, $item, $round)); 
    
    // Remove the cache file
    $likedislike->del_likecache($item['name']);
    
    // Create a cache file, so as not to create a resource for downloading. In this way we won the hundredths of seconds :)
    $likedislike->like_object($item['name']);
    
    
    // Send back the updated item.
    // Note: all the public properties of $item will be included.    
    return json_encode(array('item' => $item));
}

// A new vote has been cast successfully
return empty($error);
