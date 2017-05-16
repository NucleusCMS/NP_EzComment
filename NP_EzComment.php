<?php

class NP_EzComment extends NucleusPlugin
{
    function getEventList()   { return array();}
    function getName()        { return 'Ez Comment';}
    function getAuthor()      { return 'Taka + shizuki';}
    function getURL()         { return 'https://github.com/NucleusCMS/NP_EzComment';}
    function getVersion()     { return '0.4';}
    function getDescription() { return  _NP_EZCOMMENT_DESCRIPTION;}
    function supportsFeature($what) {return in_array($what,array('SqlTablePrefix','SqlApi'));}

    function install() {
        $this->createOption('order', _NP_EZCOMMENT_ORDER, 'select', 1, _NP_EZCOMMENT_ORDER_VALUE);
        $this->createOption('sort',  _NP_EZCOMMENT_SORT,  'select', 1, _NP_EZCOMMENT_SORT_VALUE);
    }

    function init()
    {
        $language = str_replace(array('\\','/'), '', getLanguageName());
        if (is_file($this->getDirectory()  . $language . '.php')) {
            include_once($this->getDirectory() . $language . '.php');
        }else {
            include_once($this->getDirectory() . 'english.php');
        }
    }


    function doTemplateVar(&$item, $type = '', $limit = 10, $trimwidth = 70)
    {
        global $CONF, $manager, $member, $catid;

        if ($item->closed) {
            echo _ERROR_ITEMCLOSED;
            return;
        }

        if ($this->getOption('order') == 1) {
            $line = array(1, 2);
        } else {
            $line = array(2, 1);
        }
        $sort   = $this->getOption('sort');
        $params = func_get_args();
        if (is_numeric($params[1])) {
            $limit = $params[1];
        }
        if ($type != 'list' && $type != 'form') {
            $type = '';
        }
        $limit = intval($limit);

        $itemid     = intval($item->itemid);
        $linkparams = array();
        if ($catid) {
            $linkparams['catid'] = $catid;
        }
// <subcategories mod by shizuki/>
        if ($manager->pluginInstalled('NP_MultipleCategories')) {
            $mcategories = $manager->getPlugin('NP_MultipleCategories');
            if ($mcategories) {
                global $subcatid;
                if (method_exists($mcategories, "getRequestName")) {
                    $mcategories->event_PreSkinParse(array());
                    $subrequest = $mcategories->getRequestName();
                } else {
                    $subrequest = 'subcatid';
                }
                if ($subcatid) {
                    $linkparams[$subrequest] = intval($subcatid);
                }
            }
        }
// </ subcategories mod by shizuki/>
        $itemuri = createItemLink($itemid, $linkparams);

        $blogid   =  getBlogIDFromItemID($itemid);
        $settings =& $manager->getBlog($blogid);
        $settings->readSettings();

        $membername = $member->getDisplayName();

        foreach ($line as $val) {
            switch ($val) {
                case 1:
                    if ((!$membername && !$settings->commentsEnabled()) || $type == 'list') {
                        break;
                    } else {
                        $this->showForm($itemid, $itemuri, $membername);
                    }
                    break;
                case 2:
                    if ($type != 'form') {
                        $this->showComment($limit, $itemid, $itemuri, $trimwidth, $sort);
                    }
                    break;
            }
        }
    }


// FORM START ---------------------------------------
    function showForm ($itemid, $itemuri, $membername)
    {
        global $manager, $CONF;

        $actionphp = $CONF['ActionURL'];

        if ($membername) { // member
            $nameArea = _NP_EZCOMMENT_MEMBERNAME . $membername
                      . ' (<a href="?action=logout">_LOGOUT</a>)';
            $mailArea = ' ';
            $checkBox = '';
            $type     = 'commentform-loggedin';
        } else { // non member
                if (cookieVar('comment_user')) {
//                    $username = htmlspecialchars(cookieVar('comment_user'), ENT_QUOTES, _CHARSET);
                    $username =$this->_hsc(cookieVar('comment_user'));
                } else {
                    $username = '';
                }
                if (cookieVar('comment_userid')) {
//                    $userid = htmlspecialchars(cookieVar('comment_userid'), ENT_QUOTES, _CHARSET);
                    $userid = $this->_hsc(cookieVar('comment_userid'));
                } else {
                    $userid = '';
                }
                cookieVar('comment_user') ? $check = 'checked="checked" ' : $check = '';
                    
            $nameArea = _COMMENTFORM_NAME . ': <input name="user" value="'
                      . $username
                      . '" size="10" maxlength="60" class="formfield" />';
            $mailArea = _COMMENTFORM_EMAIL . ': <input name="userid" value="'
                      . $userid
                      . '"size="20" maxlength="60" class="formfield" /><br />';
            $checkBox = '<input type="checkbox" value="1" name="remember" '
                      . $check
                      . '/>' . _COMMENTFORM_REMEMBER;
            $type     = 'commentform-notloggedin';
        }
        $addComment  = _NP_EZCOMMENT_ADDCOMMENT;
        $submitValue = _NP_EZCOMMENT_SUBMIT;

echo <<<___COMMENTFORM__

<form method="post" action="{$actionphp}"> 
    <div class="commentform"> 
        <input type="hidden" name="action" value="addcomment" />
    <!-- redirect URL -->
        <input type="hidden" name="url" value="{$itemuri}" />
        <input type="hidden" name="itemid" value="{$itemid}" />
    <!-- TextArea -->
        {$addComment}:<br />
        <textarea name="body" cols="43" rows="5" class="formfield"></textarea><br />
    <!-- Name and Checkbox -->
        {$nameArea}
    <!-- Mail or URL -->
        {$mailArea}
___COMMENTFORM__;
        $param = array('type' => $type);
        $manager->notify('FormExtra', $param);
echo <<<___COMMENTFORM__
    <!-- Submit buttom -->
         {$checkBox}<input type="submit" value="{$submitValue}" class="formbutton" />
  </div> 
</form>
___COMMENTFORM__;

    }
// FORM END -----------------------------------------


// LIST START ---------------------------------------
    function showComment($limit, $itemid, $itemuri, $trimwidth, $sort)
    {
        $countQuery = 'SELECT '
                    . 'COUNT(*) as result '
                    . 'FROM ' . sql_table('comment') . ' as c '
                    . 'WHERE c.citem=' . intval($itemid);
        $postnum    = quickQuery($countQuery);
        if ($limit && $limit < $postnum && $sort) {
            $startnum = $postnum - $limit;
        } else {
            $startnum = 0;
        }
        $order = ($sort > 1) ? "DESC" : "ASC";

        $query = 'SELECT '
               . 'c.cnumber, '
               . 'c.cbody, '
               . 'c.cuser, '
               . 'c.cmember'
               . ' FROM ' . sql_table('comment') . ' as c'
               . ' WHERE c.citem=' . intval($itemid)
               . ' ORDER BY c.ctime ' . $order;
        if ($limit) {
            if ($order == "DESC") {
                $query .=' LIMIT ' . intval($limit);
            } else {
                $query .=' LIMIT ' . intval($startnum) . ',' . intval($limit);
            }
        }

        $comments = sql_query($query);
        $viewnum  = sql_num_rows($comments);

        if ($postnum) { // display when exist comment(s)
                
    /* comment-list-header */
            // $viewnum is amount, $postnum is all
            // there are same when no limit

            echo '<div class="commentlist">' . "\n" . '--- ' . _COMMENTS . ' ' . $viewnum;
            if ($postnum > $viewnum) {
                echo '/'.$postnum;
            } else {
                echo _NP_EZCOMMENT_COUNT;
            } // there are change or commentout if you need
            $printData = ' [ <a href="' . $itemuri . '#comment">'
                       . _NP_EZCOMMENT_WHILESENTENCE
                       . "</a> ] ---\n<ul>\n";
            echo $printData;

            while ($row = sql_fetch_object($comments)) {
                $body = strip_tags($row->cbody);
                $body = str_replace("\r\n", "\r", $body); 
                $body = str_replace("\r",   "\n", $body); 
                $body = str_replace("\n",   ' ',  $body);
                $body = shorten($body, $trimwidth, "...");

                $uri  = $itemuri . '#c' . intval($row->cnumber);

                if (!($myname = $row->cuser)){
                    $mem    = new MEMBER;
                    $mem->readFromID($row->cmember);
                    $myname = $mem->getDisplayName();
                }

        /* comment-list-body */
                $printData = '  <li><a href="' . $uri . '">'
                           . $this->_hsc($myname)
                           . ' : '
                           . $this->_hsc($body)
                           . '</a></li>' . "\n";
                echo $printData;

            }
        /* comment-list-footer */
            echo "</ul>\n</div>\n";

            sql_free_result($comments);
        }
    }
// LIST END -----------------------------------------

    function _hsc($str)
    {
        return htmlspecialchars($str, ENT_QUOTES, _CHARSET);
    }
}
