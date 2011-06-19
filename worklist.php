<?php
//  Copyright (c) 2011, LoveMachine Inc.
//  All Rights Reserved.
//  http://www.lovemachineinc.com
//  vim:ts=4:et

require_once("config.php");
if (!empty($_SERVER['PATH_INFO'])) {  header( 'Location: https://'.SERVER_NAME.'/worklist/worklist.php'); }
require_once("class.session_handler.php");
include_once("check_new_user.php"); 
require_once("functions.php");
require_once("send_email.php");
require_once("update_status.php");
require_once("workitem.class.php");
require_once('lib/Agency/Worklist/Filter.php');
require_once('classes/UserStats.class.php');
require_once('classes/Repository.class.php');
require_once('classes/Project.class.php');

$page = isset($_REQUEST["page"]) ? intval($_REQUEST["page"]) : 1; //Get the page number to show, set default to 1

$userId = getSessionUserId();

if( $userId > 0 ) {
    initUserById($userId);
    $user = new User();
    $user->findUserById( $userId );
    $nick = $user->getNickname();
    $userbudget =$user->getBudget();
    $budget = number_format($userbudget);
}

$is_runner = !empty($_SESSION['is_runner']) ? 1 : 0;
$is_payer = !empty($_SESSION['is_payer']) ? 1 : 0;

// are we on a project page? see .htaccess rewrite
$projectName = !empty($_REQUEST['project']) ? mysql_real_escape_string($_REQUEST['project']) : 0;
if ($projectName) {
    $inProject = new Project();
    $inProject->loadByName($projectName);
    // save changes to project
    if (isset($_REQUEST['save_project']) && $inProject->isOwner($userId)) {
        $inProject->setDescription($_REQUEST['description']);
        $inProject->save();
        // we clear post to prevent the page from redirecting
        $_POST = array();
    }
} else {
    $inProject = false;
}

$journal_message = '';
$nick = '';

$workitem = new WorkItem();
// get active projects
$projects = Project::getProjects(true);

// check if we are on a project page, and setup filter
if (is_object($inProject)) {
    $project_id = $inProject->getProjectId();
    $filter = new Agency_Worklist_Filter();
    $filter->setName('.worklist')
           ->setProjectId($project_id)
           ->initFilter();
    $hide_project_column = true;
} else {
    $hide_project_column = false;
    $filter = new Agency_Worklist_Filter();
// krumch 20110418 Set to open Worklist from Journal
    if(isset($_REQUEST['journal_query'])) {
        $filter->setName('.worklist')
               ->setStatus(strtoupper($_REQUEST['status']))
               ->initFilter();
    } else {
        $filter->setName('.worklist')
               ->initFilter();
    }
}
// save,edit,delete roles <mikewasmie 16-jun-2011>
if (is_object($inProject) && $inProject->isOwner($userId)) {
    if ( isset($_POST['save_role'])) {
        $args = array('role_title', 'percentage', 'min_amount');
        foreach ($args as $arg) {
            $$arg = mysql_real_escape_string($_POST[$arg]);
        }
        $role_id=$inProject->addRole($project_id,$role_title,$percentage,$min_amount);
    }

    if (isset($_POST['edit_role'])) {
        $args = array('role_id','role_title_edit', 'percentage_edit', 'min_amount_edit');
        foreach ($args as $arg) {
            $$arg = mysql_real_escape_string($_POST[$arg]);
        }
        $res=$inProject->editRole($role_id,$role_title_edit,$percentage_edit,$min_amount_edit);
    }

    if (isset($_POST['delete_role'])) {
        $role_id = mysql_real_escape_string($_POST['role_id']);
        $res=$inProject->deleteRole($role_id);
    }
}

if ($userId > 0 && isset($_POST['save_item'])) {
    $args = array( 'itemid', 'summary', 'project_id', 'status', 'notes', 
                    'bid_fee_desc', 'bid_fee_amount','bid_fee_mechanic_id',
                     'invite', 'is_expense', 'is_rewarder', 'is_bug', 'bug_job_id');
    foreach ($args as $arg) {
            // Removed mysql_real_escape_string, because we should 
            // use it in sql queries, not here. Otherwise it can be applied twice sometimes
        $$arg = !empty($_POST[$arg])?$_POST[$arg]:'';
    }

    $creator_id = $userId;

    if (!empty($_POST['itemid'])) {
        $workitem->loadById($_POST['itemid']);
        $journal_message .= $nick . " updated ";
    } else {
        $workitem->setCreatorId($creator_id);
        $journal_message .= $nick . " added ";
    }
    $workitem->setSummary($summary);

    $workitem->setBugJobId($bug_job_id);
    // not every runner might want to be assigned to the item he created - only if he sets status to 'BIDDING'
    if($status == 'BIDDING' && ($user->getIs_runner() == 1 || $user->getBudget() > 0)){
        $runner_id = $userId;
    }else{
        $runner_id = 0;
    }

    $workitem->setRunnerId($runner_id);
    $workitem->setProjectId($project_id);
    $workitem->setStatus($status);
    $workitem->setNotes($notes);
    $workitem->is_bug = isset($is_bug) ? true : false;
    $workitem->save();

    Notification::statusNotify($workitem);
    if(is_bug) {
        $bug_journal_message = " (bug of job #".$bug_job_id.")";
        notifyOriginalUsersBug($bug_job_id, $workitem);
    }
    
    if(empty($_POST['itemid']))  {
        $bid_fee_itemid = $workitem->getId();
        $journal_message .= " item #$bid_fee_itemid: $summary. ";
        if (!empty($_POST['files'])) {
            $files = explode(',', $_POST['files']);
            foreach ($files as $file) {
                $sql = 'UPDATE `' . FILES . '` SET `workitem` = ' . $bid_fee_itemid . ' WHERE `id` = ' . (int)$file;
                mysql_query($sql);
            }
        }
    } else {
        $bid_fee_itemid = $itemid;
        $journal_message .=  "item #$itemid$bug_journal_message: $summary. ";
    }
        
    if (!empty($_POST['invite'])) {
        $people = explode(',', $_POST['invite']);
        invitePeople($people, $bid_fee_itemid, $summary, $notes);
    }

    if ($bid_fee_amount > 0) {
        $journal_message .= AddFee($bid_fee_itemid, $bid_fee_amount, 'Bid', $bid_fee_desc, $bid_fee_mechanic_id, $is_expense, $is_rewarder);
    }
} 

if (!empty($journal_message)) {
    //sending journal notification
    $data = array();
    $data['user'] = JOURNAL_API_USER;
    $data['pwd'] = sha1(JOURNAL_API_PWD);
    $data['message'] = stripslashes($journal_message);
    $prc = postRequest(JOURNAL_API_URL, $data);
}

// Load roles table id owner <mikewasmike 15-jun 2011>
if(is_object($inProject) && $inProject->isOwner($userId)){
    $roles = $inProject->getRoles($inProject->getProjectId());
}

/* Prevent reposts on refresh */
if (!is_object($inProject) && !empty($_POST)) {
    unset($_POST);
    header("Location:worklist.php");
    exit();
}

/*********************************** HTML layout begins here  *************************************/
$worklist_id = isset($_REQUEST['job_id']) ? intval($_REQUEST['job_id']) : 0;

include("head.html"); ?>
<!-- Add page-specific scripts and styles here, see head.html for global scripts and styles  -->
<?php if(isset($_REQUEST['addFromJournal'])) { ?>
<link href="css/addFromJournal.css" rel="stylesheet" type="text/css">
<?php } ?>
<link href="css/worklist.css" rel="stylesheet" type="text/css">
<link href="css/ui.toaster.css" rel="stylesheet" type="text/css">
<script type="text/javascript" src="js/jquery.livevalidation.js"></script>
<script type="text/javascript" src="js/jquery.autocomplete.js"></script>
<script type="text/javascript" src="js/jquery.tablednd_0_5.js"></script>
<script type="text/javascript" src="js/jquery.template.js"></script>
<script type="text/javascript" src="js/jquery.metadata.js"></script>
<script type="text/javascript" src="js/jquery.jeditable.min.js"></script>
<script type="text/javascript" src="js/worklist.js"></script>
<script type="text/javascript" src="js/timepicker.js"></script>
<script type="text/javascript" src="js/ajaxupload.js"></script>
<script type="text/javascript" src="js/jquery.tabSlideOut.v1.3.js"></script>
<script type="text/javascript" src="js/ui.toaster.js"></script>
<script type="text/javascript" src="js/userstats.js"></script>
<script type="text/javascript">
    var lockGetWorklist = 0;
    var status_refresh = 5 * 1000;
    var statusTimeoutId = null;
    var lastStatus = 0;
    function GetStatus(source) {
        var url = 'update_status.php';
        var action = 'get';
        if(source == 'journal') {
            url = '<?php echo JOURNAL_QUERY_URL; ?>';
            action = 'getUserStatus';
        }
        $.ajax({
            type: "POST",
            url: url,
            cache: false,
            data: {
                action: action
            },
            dataType: 'json',
            success: function(json) {
                if(json && json[0] && json[0]["timeplaced"]) {
                    if(lastStatus < json[0]["timeplaced"]) {
                        lastStatus = json[0]["timeplaced"];
                        $('#status-update').val(json[0]["status"]);
                        $('#status-update').hide();
                        $('#status-lbl').show();
                        $("#status-share").hide();
                        $('#status-lbl').html( '<b>' + json[0]["status"] + '</b>' );
                    }
               }
            }
        });
        statusTimeoutId = setTimeout("GetStatus('journal')", status_refresh);
    }
    // This variable needs to be in sync with the PHP filter name
    var filterName = '.worklist';
    var affectedHeader = false;
    var directions = {"ASC":"images/arrow-up.png","DESC":"images/arrow-down.png"};
    var refresh = <?php echo AJAX_REFRESH ?> * 1000;
    var lastId;
    var page = <?php echo $page ?>;
    var topIsOdd = true;
    var timeoutId;
    var addedRows = 0;
    var workitem = 0;
//    var cur_user = false;
    var workitems;
    var dirDiv;
    var dirImg;
// Ticket #11517, replace all the "isset($_SESSION['userid']) ..."  by a call to "getSessionUserId"
//   var user_id = <?php echo isset($_SESSION['userid']) ? $_SESSION['userid'] : '"nada"' ?>;
    var user_id = <?php echo getSessionUserId(); ?>;
    var is_runner = <?php echo $is_runner ? 1 : 0 ?>;
    var runner_id = <?php echo !empty($runner_id) ? $runner_id : 0 ?>;
    var is_payer = <?php echo $is_payer ? 1 : 0 ?>;
    var addFromJournal = '<?php echo isset($_REQUEST['addFromJournal']) ? $_REQUEST['addFromJournal'] : '' ?>';
    var dir = '<?php echo $filter->getDir(); ?>';
    var sort = '<?php echo $filter->getSort(); ?>';
    var inProject = '<?php echo is_object($inProject) ?  $project_id  : '';?>';
    var resetOrder = false;
    var worklistUrl = '<?php echo SERVER_URL; ?>';
    stats.setUserId(user_id);
    var activeProjectsFlag = true;
    var skills = null;

    function AppendPagination(page, cPages, table)    {
    // support for moving rows between pages
    if(table == 'worklist') {
        // preparing dialog
        $('#pages-dialog select').remove();
        var selector = $('<select>');
        for (var i = 1; i <= cPages; i++) {
            selector.append('<option value = "' + i + '">' + i + '</option>');
        }
        $('#pages-dialog').prepend(selector);
    }
        var pagination = '<tr bgcolor="#FFFFFF" class="row-' + table + '-live ' + table + '-pagination-row nodrag nodrop " ><td colspan="6" style="text-align:center;">Pages : &nbsp;';
        if (page > 1) {
            pagination += '<a href="<?php echo $_SERVER['PHP_SELF'] ?>?page=' + (page-1) + '">Prev</a> &nbsp;';
        }
        for (var i = 1; i <= cPages; i++) {
            if (i == page) {
                pagination += i + " &nbsp;";
            } else {
                pagination += '<a href="<?php echo $_SERVER['PHP_SELF'] ?>?page=' + i + '" >' + i + '</a> &nbsp;';
            }
        }
        if (page < cPages) {
            pagination += '<a href="<?php echo $_SERVER['PHP_SELF'] ?>?page=' + (page+1) + '">Next</a> &nbsp;';
        }
        pagination += '</td></tr>';
        $('.table-' + table).append(pagination);
    }
    function return2br(dataStr) {
        return dataStr.replace(/(\r\n|\r|\n)/g, "<br />");
    }
    // see getworklist.php for json column mapping
    function AppendRow (json, odd, prepend, moreJson, idx) {
        var pre = '', post = '';
        var row;
        row = '<tr id="workitem-' + json[0] + '" class="row-worklist-live iToolTip hoverJobRow ';

        // disable dragging for all rows except with "BIDDING" status
        if (json[2] != 'BIDDING'){
            row += ' nodrag ';
        }

        if (odd) { 
            row += ' rowodd';
        } else { 
            row += 'roweven';
        }

        // Check if the user is either creator, runner, mechanic and assigns the rowown class
        // also highlight expired and tasks bidding on
        if (user_id == 0) { // Checks if a user is logged in, as of now it 
                            // would show to non logged in users, since mechanic 
                            // aren't checked for session
        } else if(user_id == json[13]) {// Runner
            row += ' rowrunner'; 
        } else if(user_id == json[14]) {// Mechanic
            row += ' rowmechanic'; 
        } else if(json[15] ==1) { //user bid on this task
            row += ' rowbidon';
        } else if(json[19] == 'expired') { // bid expired
            row += ' rowbidexpired';
        } else if(user_id == json[9]) { // Creator
            row += ' rowown';
        }

        row += '">';
        if (prepend) {
            pre = '<div class="slideDown" style="display:none">';
            post = '</div>';
        }

<?php if (! $hide_project_column) : ?>
        row+= '<td width="9%"><span class="taskProject" id="' + json[16] + '"><a href="' + worklistUrl + '' + json[17] + '">' + (json[17] == null ? '' : json[17]) + '</a></span></td>';
<?php endif; ?>
        //If job is a bug, add reference to original job
        if( json[18] > 0) {
            extraStringBug = '<small> (bug of '+json[18]+ ') </small>';
        } else {
            extraStringBug = '';
        }
  
        // Displays the ID of the task in the first row
        // 26-APR-2010 <Yani>
        row += '<td width="41%"><span class="taskSummary"><span class="taskID">#' + 
                json[0] + '</span> - ' + pre + json[1] + post + extraStringBug + 
                '</span></td>';
<?php if (! $hide_project_column) : ?>
        if (json[2] == 'BIDDING' && json[10] > 0) {
            post = ' (' + json[10] + ')';
        }
        row += '<td width="10%">' + pre + json[2] + post + '</td>';
<?php endif; ?>
        pre = '';
        post = '';
/*
        if (json[3] != '') {
            var who = json[3];
            var tooltip = "Owner: "+json[4];
            if(json[9] != null && json[3] != json[9]) {
                who +=  ', ' + json[9];
                tooltip += '<br />Mechanic: '+json[10];
            }
            
            row += '<td width="15%" class="toolparent">' + pre + who + post + '<span class="tooltip">' + tooltip  + '</span>' + '</td>';
        } else {
            row += '<td width="15%">' + pre + json[3] + post + '</td>';
        }
*/
    <?php if (! $hide_project_column) : ?>
    var who = '',
        createTagWho = function(id,nickname,type) {
            return '<span class="'+type+'" title="' + id + '">'+nickname+'</span>';
        };
    if(json[3] == json[4]){
    
        // creator nickname can't be null, so not checking here
        who += createTagWho(json[9],json[3],"creator");
    }else{

        var runnerNickname = json[4] != null ? ', ' + createTagWho(json[13],json[4],"runner") : '';
        who += createTagWho(json[9],json[3],"creator") + runnerNickname;
    }
    if(json[5] != null){

        who += ', ' + createTagWho(json[14],json[5],"mechanic");
    }

    row += '<td width="9.5%" class="who">' + pre + who + post + '</td>';

        if (json[2] == 'WORKING' && json[11] != null) {
            row += '<td width="15%">' + pre + (RelativeTime(json[11]) + ' from now').replace(/0 sec from now/,'Past due') + post +'</td>';
        } else {
            row += '<td width="15%">' + pre + RelativeTime(json[6]) + ' ago' + post +'</td>';
        }

        // Comments
        row += '<td width="7.5%">' + json[12] + '</td>';

        if (is_runner == 1) {
             var feebids = 0;
            if(json[7]){
                feebids = json[7];
            }
            var bid = 0;
            if(json[8]){
                bid = json[8];
            }
            if(json[2] == 'BIDDING'){
                bid = parseFloat(bid);
                if (bid == 0) {
                    feebids = '';
                } else {
                    feebids = '$' + parseFloat(bid);
                }
            } else {
                feebids = '$' + feebids;
            }
            row += '<td width="11%">' + pre + feebids + post + '</td>';
        }
        <?php endif; ?>
        row += '</tr>';
        if (prepend) {
            $(row).prependTo('.table-worklist tbody')
                .find('td div.slideDown').fadeIn(500);
            setTimeout(function(){
                $(this).removeClass('slideDown');
                if (moreJson && idx-- > 1) {
                    topIsOdd = !topIsOdd;
                    AppendRow(moreJson[idx], topIsOdd, true, moreJson, idx);
                }
            }, 500);
        } else {
            $('.table-worklist tbody').append(row);
        }
    }

    function Change(obj, evt)    {
        if(evt.type=="focus")
            obj.style.borderColor="#6A637C";
        else if(evt.type=="blur")
           obj.style.borderColor="#d0d0d0";
    }

    function ClearSelection () {
        if (document.selection)
            document.selection.empty();
        else if (window.getSelection)
            window.getSelection().removeAllRanges();
    }
    
    function SetWorkItem(item) {
        var match = item.attr('id').match(/workitem-\d+/);
        if (match) {
            workitem = match[0].substr(9);
        } else {
            workitem = 0;
        }
        return workitem;
    }

    function orderBy(option) {
        if (option == sort) dir = ((dir == 'asc')? 'desc':'asc');
        else {
            sort = option;
            dir = 'asc';
        }
        GetWorklist(1,false);
    }

    function resizeIframeDlg() {
        var bonus_h = $('#user-info').children().contents().find('#pay-bonus').is(':visible') ?
                      $('#user-info').children().contents().find('#pay-bonus').closest('.ui-dialog').height() : 0;
    
        var dlg_h = $('#user-info').children()
                                   .contents()
                                   .find('html body')
                                   .height();
    
        var height = bonus_h > dlg_h ? bonus_h+35 : dlg_h+30;
    
        $('#user-info').animate({height: height});
    }

    function showUserInfo(userId) {
        $('#user-info').html('<iframe id="modalIframeId" width="100%" height="100%" marginWidth="0" marginHeight="0" frameBorder="0" scrolling="auto" />').dialog('open');
        $('#modalIframeId').attr('src','userinfo.php?id=' + userId);
        return false;
    };

    function GetWorklist(npage, update, reload) {
        if(addFromJournal != '') {
            return true;
        }
        while(lockGetWorklist) {
// count atoms of the Universe untill old instance finished...
        }
        lockGetWorklist = 1;
        if (timeoutId) {
            clearTimeout(timeoutId);
        }
        loaderImg.show("loadRunning", "Loading, please wait ...");
        $.ajax({
            type: "POST",
            url: 'getworklist.php',
            cache: false,
            data: {
                page: npage,
                project_id: $('.projectComboList .ui-combobox-list-selected').attr('val') || inProject,
                status: ($('select[name=status]').val() || []).join("/"),
                sort: sort,
                dir: dir,
                user: $('.userComboList .ui-combobox-list-selected').attr('val'),
                query: $("#query").val(),
                reload: ((reload == undefined) ? false : true)
            },
            dataType: 'json',
            success: function(json) {
                if (json[0] == "redirect") {
                    lockGetWorklist = 0;
                    $("#query").val('');
                    window.location.href = buildHref( json[1] );
                    return false;
                }
                
                loaderImg.hide("loadRunning");
                if (affectedHeader) {
                    affectedHeader.append(dirDiv);
                    dirImg.attr('src',directions[dir.toUpperCase()]);
                    dirDiv.css('display','block');
                } else {
                    if (resetOrder) {
                        dirDiv.css('display','none');
                        resetOrder = false;
                    }
                }
                affectedHeader = false;
                page = json[0][1]|0;
                var cPages = json[0][2]|0;

                $('.row-worklist-live').remove();
                workitems = json;
                if (!json[0][0]) return;

                /* When updating, find the last first element */
                for (var lastFirst = 1; update && page == 1 && lastId && lastFirst < json.length && lastId != json[lastFirst][0]; lastFirst++);
                lastFirst = Math.max(1, lastFirst - addedRows);
                addedRows = 0;

                /* Output the worklist rows. */
                var odd = topIsOdd;
                for (var i = lastFirst; i < json.length; i++) {
                    AppendRow(json[i], odd);
                    odd = !odd;
                }
                
                AppendPagination(page, cPages, 'worklist');

                if (update && lastFirst > 1) {
                    /* Update the view by scrolling in the new entries from the top. */
                    topIsOdd = !topIsOdd;
                    AppendRow(json[lastFirst-1], topIsOdd, true, json, lastFirst-1);
                }
                lastId = json[1][0];
                
                /*commented for remove tooltip */
                //MapToolTips();
                $('tr.row-worklist-live').hover(
                    function() {
                        var selfRow=$(this);
                        $(".taskID,.taskSummary",this).wrap("<a href='" + 
                            buildHref( SetWorkItem(selfRow) ) + 
                            "'></a>");
                        $(".creator,.runner,.mechanic",$(".who",this)).toggleClass("linkTaskWho").click(
                            function() {
                                showUserInfo($(this).attr("title"));
                            }
                        );
                    },function() {
                        $(".taskID,.taskSummary",this).unwrap();
                        $(".creator,.runner,.mechanic",$(".who",this)).toggleClass("linkTaskWho").unbind("click");;
                });

                $('.worklist-pagination-row a').click(function(e){
                    page = $(this).attr('href').match(/page=\d+/)[0].substr(5);
                    if (timeoutId) clearTimeout(timeoutId);
                    GetWorklist(page, false);
                    e.stopPropagation();
                    lockGetWorklist = 0;
                    return false;
                });

            },
            error: function(xhdr, status, err) {
                $('.row-worklist-live').remove();
                $('.table-worklist').append('<tr class="row-worklist-live rowodd"><td colspan="5" align="center">Oops! We couldn\'t find any work items.  <a id="again" href="#">Please try again.</a></td></tr>');
//              Ticket #11560, hide the waiting message as soon as there is an error
                loaderImg.hide("loadRunning");
//              Ticket #11596, fix done with 11517
//              $('#again').click(function(){
                $('#again').click(function(e){
//                  loaderImg.hide("loadRunning");
                    if (timeoutId) clearTimeout(timeoutId);
                    GetWorklist(page, false);
                    e.stopPropagation();
                    lockGetWorklist = 0;
                    return false;
                });
            }
        });

        timeoutId = setTimeout("GetWorklist("+page+", true, true)", refresh);
        lockGetWorklist = 0;
    }


    /*
    *    aneu: Added jquery.hovertip.min.js 
    *          Is this function below needed or used somewhere?
    */
    function ToolTip() {
        xOffset = 10;
        yOffset = 20;
        var el_parent, el_child;
        $(".toolparent").hover(function(e){
            if (el_child) el_child.appendTo(el_parent).hide();
            el_parent = $(this);
            el_child = el_parent.children(".tooltip")
                .appendTo("body")
                .css("top",(e.pageY - xOffset) + "px")
                .css("left",(e.pageX + yOffset) + "px")
                .fadeIn("fast");
        },
        function(){
            if (el_child) el_child.appendTo(el_parent);
            $(".tooltip").hide();
            el_child = null;
        });
        $(".toolparent").mousemove(function(e){
            if (el_child) {
                el_child
                    .css("top",(e.pageY - xOffset) + "px")
                    .css("left",(e.pageX + yOffset) + "px");
            }
        });
    }

    function buildHref(item ) {
        return "<?php echo SERVER_URL ; ?>workitem.php?job_id="+item+"&action=view";
    }

    function ResetPopup() {
        $('#for_edit').show();
        $('#for_view').hide();
        $('.popup-body form input[type="text"]').val('');
        $('.popup-body form select.resetToFirstOption option[index=0]').attr('selected', 'selected');        
        $('.popup-body form select option[value=\'BIDDING\']').attr('selected', 'selected');
        $('.popup-body form textarea').val('');

        //Reset popup edit form
        $("#bug_job_id").attr ( "disabled" , true );
        $("#bug_job_id").val ("");
        $('#bugJobSummary').html('');
        $("#bugJobSummary").attr("title" , 0);
        $("#is_bug").attr('checked',false);
    }



    jQuery.fn.center = function () {
      this.css("position","absolute");
      this.css("top", (( $(window).height() - this.outerHeight() ) / 2 ) + "px");
      this.css("left", (( $(window).width() - this.outerWidth() ) / 2 ) + "px");
      return this;
    }
    /*
    show a message with a wait image
    several asynchronus calls can be made with different messages 
    */
    var loaderImg = function($)
    {
        var aLoading = new Array(),
            _removeLoading = function(id) {
                for (var j=0; j < aLoading.length; j++) {
                    if (aLoading[j].id == id) {
                        if (aLoading[j].onHide) {
                            aLoading[j].onHide();
                        }
                        aLoading.splice(j,1);
                    }
                }
            },
            _show = function(id,title,callback) {
                aLoading.push({ id : id, title : title, onHide : callback});
                $("#loader_img_title").append("<div class='"+id+"'>"+title+"</div>");
                if (aLoading.length == 1) {
                    $("#loader_img").css("display","block");
                }
                $("#loader_img_title").center();
            },
            _hide = function(id) {
                _removeLoading(id);
                if (aLoading.length == 0) {
                    $("#loader_img").css("display","none");     
                    $("#loader_img_title div").remove();
                } else {
                    $("#loader_img_title ."+id).remove();
                    $("#loader_img_title").center();
                }
            };
        
    return {
        show : _show,
        hide : _hide
    };

    }(jQuery); // end of function loaderImg


    $(document).ready(function() {
        // Fix the layout for the User selection box
        var box_h = $('select[name=user]').height() +1;
        $('#userbox').css('margin-top', '-'+box_h+'px');

        $.get('getskills.php', function(data) {
            var skills = eval(data);
            $("#skills").autocomplete(skills, {
                width: 320,
                max: 10,
                highlight: false,
                multiple: true,
                multipleSeparator: ", ",
                scroll: true,
                scrollHeight: 300
            });
        });

        dirDiv = $("#direction");
        dirImg = $("#direction img");
        hdr = $(".table-hdng");
        if (sort != 'delta') {
            hdr.find(".clickable").each(function() {
                if ($(this).text().toLowerCase() == unescape(sort.toLowerCase())) {
                    affectedHeader = $(this);
                }
            });
        }
        else {
            affectedHeader = $('#defaultsort');
        }
        hdr.find(".clickable").click(function() {
            affectedHeader = $(this);
            orderBy($(this).text().toLowerCase());
        });
        if(addFromJournal != '') {
            var winHandle = window.open('', addFromJournal);
            var addJobPane = (winHandle.document.getElementById)?winHandle.document.getElementById('addJobPane'):winHandle.document.all['addJobPane'];
        }
        
        // new dialog for adding and editing roles <mikewasmike 16-jun-2011>
        $('#popup-addrole').dialog({ autoOpen: false, modal: true, maxWidth: 600, width: 450, show: 'fade', hide: 'fade' });
        $('#popup-role-info').dialog({ autoOpen: false, modal: true, maxWidth: 600, width: 450, show: 'fade', hide: 'fade' });
        $('#popup-edit-role').dialog({ autoOpen: false, modal: true, maxWidth: 600, width: 450, show: 'fade', hide: 'fade' });
        $('#popup-edit').dialog({ 
            autoOpen: false,
            show: 'fade',
            hide: 'fade',
            maxWidth: 600, 
            width: 415,
            hasAutocompleter: false,
            hasCombobox: false,
            resizable:false,
            open: function() {
                if (this.hasAutocompleter !== true) {
                    $('.invite').autocomplete('getusers.php', {
                        multiple: true,
                        multipleSeparator: ', ',
                        selectFirst: true,
                        extraParams: { nnonly: 1 }
                    });
                    this.hasAutocompleter = true;
                }
                if (this.hasCombobox !== true) {
                    // to add a custom stuff we bind on events
                    $('#popup-edit select[name=itemProject]').bind({
                        'beforeshow newlist': function(e, o) {
                            // check if the div for the checkbox already exists
                            if ($('#projectPopupActiveBox').length == 0) {
                                var div = $('<div/>').attr('id', 'projectPopupActiveBox');

                                // now we add a function which gets called on click
                                div.click(function(e) {
                                    // we hide the list and remove the active state
                                    activeProjectsFlag = 1 - activeProjectsFlag;
                                    o.list.hide();
                                    o.container.removeClass('ui-state-active');
                                    // we send an ajax request to get the updated list
                                    $.ajax({
                                        type: 'POST',
                                        url: 'refresh-filter.php',
                                        data: {
                                            name: filterName,
                                            active: activeProjectsFlag,
                                            filter: 'projects'
                                        },
                                        dataType: 'json',
                                        // on success we update the list
                                        success: $.proxy(o.setupNewList, null,o)
                                    });
                                });
                                $('.itemProjectCombo').append(div);
                            }
                            // setup the label and checkbox to put in the div
                            var label = $('<label/>').css('color', '#ffffff').attr('for', 'onlyActive');
                            var checkbox = $('<input/>').attr({
                                type: 'checkbox',
                                id: 'onlyActive'
                            }).css({
                                    margin: 0,
                                    position: 'relative',
                                    top: '1px',
                            });

                            // we need to update the checkbox status
                            if (activeProjectsFlag) {
                                checkbox.attr('checked', true);
                            } else {
                                checkbox.attr('checked', false);
                            }

                            // put the label + checkbox in the div
                            label.text(' Active only');
                            label.prepend(checkbox);
                            $('#projectPopupActiveBox').html(label);
                        }
                    }).comboBox();                                        
                    this.hasCombobox = true;
                } else {
                    $('#popup-edit select[name=itemProject]').next().hide();
                    setTimeout(function() {
                        var val1 = $($('#popup-edit select[name=itemProject] option').get(1)).attr("value");
                        $('#popup-edit select[name=itemProject]').comboBox({action:"val",param:[val1]});
                        setTimeout(function() {
                            $('#popup-edit select[name=itemProject]').next().show();
                            $('#popup-edit select[name=itemProject]').comboBox({action:"val",param:["select"]});
                        },50);
                    },20);
                    
                }
            },
            close: function() {
                if(addFromJournal != '') {
                    addJobPane.style.display='none';
                }
            }
        });
        $('#budget-expanded').dialog({ autoOpen: false, width:920, show:'fade', hide:'drop' });
        $('#user-info').dialog({
           autoOpen: false,
           resizable: false,
           modal: false,
           show: 'fade',
           hide: 'fade',
           width: 800,
           height: 480
        });

        GetWorklist(<?php echo $page?>, false, true);
        
        $("#owner").autocomplete('getusers.php', { cacheLength: 1, max: 8 } );
        reattachAutoUpdate();

        $('#add').click(function(){
            $('#popup-edit').data('title.dialog', 'Add Worklist Item');
            $('#popup-edit form input[name="itemid"]').val('');
            ResetPopup();
            $('#save_item').click(function(event){
                var massValidation;
                if ($('#save_item').data("submitIsRunning") === true) {
                    event.preventDefault();
                    return false;
                }
                $('#save_item').data( "submitIsRunning",true );
                loaderImg.show( "saveRunning","Saving, please wait ...",function() {
                    $('#save_item').data( "submitIsRunning",false );
                });

                if($('#popup-edit form input[name="is_bug"]').is(':checked')) {
                    var bugJobId = new LiveValidation('bug_job_id',{ 
                        onlyOnSubmit: true ,
                        onInvalid : function() {
                            loaderImg.hide("saveRunning");
                            this.insertMessage( this.createMessageSpan() ); 
                            this.addFieldClass();
                        }
                    });
                    bugJobId.add( Validate.Custom, { 
                        against: function(value,args){
                            id=$('#bugJobSummary').attr('title');
                            return (id!=0) 
                        },
                        failureMessage: "Invalid item Id"
                    });

                    massValidation = LiveValidation.massValidate( [ bugJobId ]);   
                    if (!massValidation) {
                        loaderImg.hide("saveRunning");
                        event.preventDefault();
                        return false;
                    }
                }
                if($('#popup-edit form input[name="bid_fee_amount"]').val() || $('#popup-edit form input[name="bid_fee_desc"]').val()) {
                    // see http://regexlib.com/REDetails.aspx?regexp_id=318
                    // but without  dollar sign 22-NOV-2010 <krumch>
                    var regex = /^(\d{1,3},?(\d{3},?)*\d{3}(\.\d{0,2})?|\d{1,3}(\.\d{0,2})?|\.\d{1,2}?)$/;
                    var optionsLiveValidation = { onlyOnSubmit: true,
                        onInvalid : function() {
                            loaderImg.hide("saveRunning");
                            this.insertMessage( this.createMessageSpan() ); 
                            this.addFieldClass();
                        }
                    };
                    var bid_fee_amount = new LiveValidation('bid_fee_amount',optionsLiveValidation);
                    var bid_fee_desc = new LiveValidation('bid_fee_desc',optionsLiveValidation);

                    bid_fee_amount.add( Validate.Presence, { failureMessage: "Can't be empty!" });
                    bid_fee_amount.add( Validate.Format, { pattern: regex, failureMessage: "Invalid Input!" });
                    bid_fee_desc.add( Validate.Presence, { failureMessage: "Can't be empty!" });
                    massValidation = LiveValidation.massValidate( [ bid_fee_amount, bid_fee_desc]);   
                    if (!massValidation) {
                        loaderImg.hide("saveRunning");
                        event.preventDefault();
                        return false;
                     }
                } else {
                    if (bid_fee_amount) bid_fee_amount.destroy();
                    if (bid_fee_desc) bid_fee_desc.destroy();
                }
                var summary = new LiveValidation('summary',{ onlyOnSubmit: true ,
                    onInvalid : function() {
                        loaderImg.hide("saveRunning");
                        this.insertMessage( this.createMessageSpan() );
                        this.addFieldClass();
                    }});
                summary.add( Validate.Presence, { failureMessage: "Can't be empty!" });
                massValidation = LiveValidation.massValidate( [ summary ]);
                if (!massValidation) {
                    loaderImg.hide("saveRunning");
                    event.preventDefault();
                    return false;
                }
                var itemProject = new LiveValidation('itemProjectCombo',{
                    onlyOnSubmit: true ,
                    onInvalid : function() {
                        loaderImg.hide("saveRunning");
                        this.insertMessage( this.createMessageSpan() );
                        this.addFieldClass();
                    }});
                itemProject.add( Validate.Exclusion, {
                    within: [ 'select' ], partialMatch: true,
                    failureMessage: "You have to choose a project!"
                });
                massValidation = LiveValidation.massValidate( [ itemProject ]);
                if (!massValidation) {
                    loaderImg.hide("saveRunning");
                    event.preventDefault();
                    return false;
                }
                addForm = $("#popup-edit");
                $.ajax({
                    url: 'addworkitem.php',
                    dataType: 'json',
                    data: {
                        bid_fee_amount:$(":input[name='bid_fee_amount']",addForm).val(),
                        bid_fee_mechanic_id:$(":input[name='bid_fee_mechanic_id']",addForm).val(),
                        bid_fee_desc:$(":input[name='bid_fee_desc']",addForm).val(),
                        itemid:$(":input[name='itemid']",addForm).val(),
                        summary:$(":input[name='summary']",addForm).val(),
                        files:$(":input[name='files']",addForm).val(),
                        invite:$(":input[name='invite']",addForm).val(),
                        notes:$(":input[name='notes']",addForm).val(),
                        page:$(":input[name='page']",addForm).val(),
                        project_id:$(":input[name='itemProject']",addForm).val(),
                        status:$(":input[name='status']",addForm).val(),
                        skills:$(":input[name='skills']",addForm).val(),
                        is_bug:$(":input[name='is_bug']",addForm).attr('checked'),
                        bug_job_id:$(":input[name='bug_job_id']",addForm).val()
                    },
                    type: 'POST',
                    success: function(json){
                        if ( !json || json === null ) {
                            alert("json null in addworkitem");
                            loaderImg.hide("saveRunning");
                            return;
                        }
                        if ( json.error ) {
                            alert(json.error);
                        } else {
                            $('#popup-edit').dialog('close');
                        }
                        loaderImg.hide("saveRunning");
                        if(addFromJournal != '') {
                            addJobPane.style.display='none';
                        } else {
                            if (timeoutId) clearTimeout(timeoutId);
                            timeoutId = setTimeout("GetWorklist("+page+", true, true)", refresh);
                            GetWorklist("+page+", true, true);
                        }
                    }
                });
                return false;
            });
            $('#fees_block').hide();
            $('#fees_single_block').show();
            $('#popup-edit').dialog('open');
        });
        $("#search").click(function(e){
            e.preventDefault();
            $("#searchForm").submit();
            return false;
        });
        
        $('#query').keypress(function(event) {
            if (event.keyCode == '13') {
                event.preventDefault();
                $("#search").click();
            }
        });

        $("#search_reset").click(function(e){
            e.preventDefault();
            $("#query").val('');
            affectedHeader = false;
            resetOrder = true;
            sort = 'null';
            dir = 'asc';
            GetWorklist(1,false);
            return false;
        });

        $("#searchForm").submit(function(){
            //$("#loader_img").css("display","block");
            GetWorklist(1,false);
            return false;
        });
        
        //derived from bids to show edit dialog when project owner clicks on a role <mikewasmike 16-jun-2011>
        $('tr.row-role-list-live ').click(function(){
            $.metadata.setType("elem", "script")
            var roleData = $(this).metadata();

            // row has role data attached 
            if(roleData.id){
                $('#popup-role-info input[name="role_id"]').val(roleData.id);
                $('#popup-role-info #info-title').text(roleData.role_title);
                $('#popup-role-info #info-percentage').text(roleData.percentage);
                $('#popup-role-info #info-min-amount').text(roleData.min_amount);
                //future functions to display more information as well as enable disable removal edition      
                $('#popup-role-info').dialog('open');
            }
        });
        
        $('#editRole').click(function(){
            // row has role data attached 
            $('#popup-role-info').dialog('close');
                $('#popup-edit-role input[name="role_id"]').val($('#popup-role-info input[name="role_id"]').val());
                $('#popup-edit-role #role_title_edit').val($('#popup-role-info #info-title').text());
                $('#popup-edit-role #percentage_edit').val($('#popup-role-info #info-percentage').text());
                $('#popup-edit-role #min_amount_edit').val($('#popup-role-info #info-min-amount').text());   
                $('#popup-edit-role').dialog('open');
        });

        
        //-- gets every element who has .iToolTip and sets it's title to values from tooltip.php
        /* function commented for remove tooltip */
        //setTimeout(MapToolTips, 800);

<?php if (! $hide_project_column) : ?>
    // bind on creation of newList
            $('select[name=project]').bind({
                'beforeshow newlist': function(e, o) {
                                        
                    // check if the div for the checkbox already exists
                    if ($('#projectActiveBox').length == 0) {
                        var div = $('<div/>').attr('id', 'projectActiveBox');
                        
                        // now we add a function which gets called on click
                        div.click(function(e) {
                            // we hide the list and remove the active state
                            activeProjectsFlag = 1 - activeProjectsFlag;
                            o.list.hide();
                            o.container.removeClass('ui-state-active');
                            // we send an ajax request to get the updated list
                            $.ajax({
                                type: 'POST',
                                url: 'refresh-filter.php',
                                data: {
                                    name: filterName,
                                    active: activeProjectsFlag,
                                    filter: 'projects'
                                },
                                dataType: 'json',
                                // on success we update the list
                                success: $.proxy(o.setupNewList, null,o)
                            });
                        });
                        $('.projectCombo').append(div);
                    }
                    // setup the label and checkbox to put in the div
                    var label = $('<label/>').css('color', '#ffffff').attr('for', 'onlyActive');
                    var checkbox = $('<input/>').attr({
                        type: 'checkbox',
                        id: 'onlyActive'
                    }).css({
                            margin: 0,
                            position: 'relative',
                            top: '1px',
                    });

                    // we need to update the checkbox status
                    if (activeProjectsFlag) {
                        checkbox.attr('checked', true);
                    } else {
                        checkbox.attr('checked', false);
                    }
                    
                    // put the label + checkbox in the div
                    label.text(' Active only');
                    label.prepend(checkbox);
                    $('#projectActiveBox').html(label);
                }
            }).comboBox();
<?php endif; ?>

        if(getQueryVariable('status') != null) {
            if (timeoutId) clearTimeout(timeoutId);
            GetWorklist(page, false);
        }
    });
    
    function showAddRoleForm() {
        $('#popup-addrole').dialog('open');
        return false;
    }
    function reattachAutoUpdate() {
        $("select[name=user], select[name=status], select[name=project]").change(function(){
            if ($("#search-filter").val() == 'UNPAID') {
                $(".worklist-fees").text('Unpaid');
            } else {
                $(".worklist-fees").text('Fees/Bids');
            }

            page = 1;
            if (timeoutId) clearTimeout(timeoutId);
            GetWorklist(page, false);
        });
    }

    function getIdFromPage(npage, worklist_id)  {
        $.ajax({
            type: "POST",
            url: 'getworklist.php',
            cache: false,
            data: 'page='+npage+'&sfilter='+$("#search-filter").val()+'&ufilter='+$("#user-filter").val()+"&query="+$("#query").val(),
            dataType: 'json',
            success: function(json) {
                // if moving on the greater page - place item on top, if on page with smaller number - on the end of the list
                if(npage > page){
                    prev_id = json[1][0];
                }else{
                    prev_id = json[json.length-2][0];
                }
                updatePriority(worklist_id, prev_id, 5);
            }
        });
    }
    function updatePriority(worklist_id, prev_id, bump){
        $.ajax({
            type: "POST",
            url: 'updatepriority.php',
            data: 'id='+worklist_id+'&previd='+prev_id+'&bump='+bump,
            success: function(json) {
                GetWorklist(page, true);
            }
        });
    }

    /**
     * Show a dialog with expanded info on the selected @section
     * Sections:
     *  - 0: Allocated
     *  - 1: Submited
     *  - 2: Paid
     */
    function budgetExpand(section) {
        $('#be-search-field').val('');
        $('#be-search-field').keyup(function() {
            // Search current text in the table by hiding rows
            var search = $(this).val().toLowerCase();
            
            $('.data_row').each(function() {
                var html = $(this).text().toLowerCase();
                // If the Row doesn't contain the pattern hide it
                if (!html.match(search)) {
                    $(this).fadeOut('fast');
                } else { // If is hidden but matches the pattern, show it
                    if (!$(this).is(':visible')) {
                        $(this).fadeIn('fast');
                    }
                }
            });
        });
        // If clean search, fade in any hidden items
        $('#be-search-clean').click(function() {
            $('#be-search-field').val('');
            $('.data_row').each(function() {
                $(this).fadeIn('fast');
            });
        });
        switch (section) {
            case 0:
                // Fetch new data via ajax
                $('#budget-expanded').dialog('open');
                be_getData(section);
                break;
            case 1:
                // Fetch new data via ajax
                $('#budget-expanded').dialog('open');
                be_getData(section);
                break;
            case 2:
                // Fetch new data via ajax
                $('#budget-expanded').dialog('open');
                be_getData(section);
                break;
        }
    }
    
    function be_attachEvents(section) {
        $('#be-id').click(function() {
            be_handleSorting(section, $(this));
        });
        $('#be-summary').click(function() {
            be_handleSorting(section, $(this));
        });
        $('#be-who').click(function() {
            be_handleSorting(section, $(this));
        });
        $('#be-amount').click(function() {
            be_handleSorting(section, $(this));
        });
        $('#be-status').click(function() {
            be_handleSorting(section, $(this));
        });
        $('#be-created').click(function() {
            be_handleSorting(section, $(this));
        });
        $('#be-paid').click(function() {
            be_handleSorting(section, $(this));
        });
    }
    
    function be_getData(section, item, desc) {
        // Clear old data
        var header = $('#table-budget-expanded').children()[0];
        $('#table-budget-expanded').children().remove();
        $('#table-budget-expanded').append(header);
        be_attachEvents(section);
        
        var params = '?section='+section;   
        var sortby = '';
        // If we've got an item sort by it
        if (item) {
            sortby = item.attr('id');
            params += '&sortby='+sortby+'&desc='+desc;
        }
        
        $.getJSON('get-budget-expanded.php'+params, function(data) {
            // Fill the table
            for (var i = 0; i < data.length; i++) {
                var link = '<a href="https://".SERVER_NAME."/worklist/workitem.php?job_id='+data[i].id+'&action=view" target="_blank">';
                // Separate "who" names into an array so we can add the userinfo for each one
                var who = data[i].who.split(", ");
                var who_link = '';
                for (var z = 0; z < who.length; z++) {
                    if (z < who.length-1) {
                        who[z] = '<a href="#" onclick="showUserInfo('+data[i].ids[z]+')">'+who[z]+'</a>, ';
                    } else {
                        who[z] = '<a href="#" onclick="showUserInfo('+data[i].ids[z]+')">'+who[z]+'</a>';
                    }
                    who_link += who[z];
                }
                
                var row = '<tr class="data_row"><td>'+link+'#'+data[i].id+'</a></td><td>'+link+data[i].summary+'</a></td><td>'+who_link+
                          '</td><td>$'+data[i].amount+'</td><td>'+data[i].status+'</td>'+
                          '<td>'+data[i].created+'</td>';
                if (data[i].paid != 'Not Paid') {
                    row += '<td>'+data[i].paid+'</td></tr>';
                } else {
                    row += '<td>'+data[i].paid+'</td></tr>';
                }
                $('#table-budget-expanded').append(row);
            }
        });
        $('#budget-report-export').click(function() {
            window.open('get-budget-expanded.php?section='+section+'&action=export', '_blank');
        });
    }

    function be_handleSorting(section, item) {
        var desc = true;
        if (item.hasClass('desc')) {
            desc = false;
        }
        
        // Cleanup sorting
        be_cleaupTableSorting();
        item.removeClass('asc');
        item.removeClass('desc');
        
        // Add arrow
        var arrow_up = '<div style="float:right;">'+
                       '<img src="images/arrow-up.png" height="15" width="15" alt="arrow"/>'+
                       '</div>';

        var arrow_down = '<div style="float:right;">'+
                         '<img src="images/arrow-down.png" height="15" width="15" alt="arrow"/>'+
                         '</div>';

        if (desc) {
            item.append(arrow_down);
            item.addClass('desc');
        } else {
            item.append(arrow_up);
            item.addClass('asc');
        }

        // Update Data
        be_getData(section, item, desc);
    }

    function be_cleaupTableSorting() {
        $('#be-id').children().remove();
        $('#be-summary').children().remove();
        $('#be-who').children().remove();
        $('#be-amount').children().remove();
        $('#be-status').children().remove();
        $('#be-created').children().remove();
        $('#be-paid').children().remove();
    }
    
    if(addFromJournal != '') {
        $(function() {
            $('#add').click();
        });
    }
</script>
<script type="text/javascript" src="js/utils.js"></script>
<?php
// !!! This code is duplicated in workitem.inc
// !!! [START DUP]
?>
<script type="text/html" id="uploadedFiles">
<div id="accordion">
    <h3><a href="#">Images (<span id="imageCount"><#= images.length #></span>)</a></h3>
    <div id="fileimagecontainer">
    <# if (images.length > 0) { #>
        <# for(var i=0; i < images.length; i++) {
        var image = images[i];
        #>
        <div class="filesIcon">
            <a href="<#= image.url #>"><img width="75px" height="75px" src="<#= image.icon #>" /></a>
        </div>
        <div class="filesDescription">
            <h3 class="edittext" id="fileTitle_<#= image.fileid #>"><#= image.title #></h3>
            <p class="edittextarea" id="fileDesc_<#= image.fileid #>"><#= image.description #></p>
            <a class="removeAttachment" id="fileRemoveAttachment_<#= image.fileid #>" href="javascript:;">Remove attachment</a>
        </div>
        <div class="clear"></div>
        <# } #>
    <# } #>
    </div>
    <h3><a href="#">Documents (<span id="documentCount"><#= documents.length #></span>)</a></h3>
    <div id="filedocumentcontainer">
    <# if (documents.length > 0) { #>
        <# for(var i=0; i < documents.length; i++) {
        var doc = documents[i];
        #>
        <div class="filesIcon">
            <a href="<#= doc.url #>" target="_blank"><img width="32px" height="32px" src="<#= doc.icon #>" /></a>
        </div>
        <div class="documents filesDescription">
            <h3 class="edittext" id="fileTitle_<#= doc.fileid #>"><#= doc.title #></h3>
            <p class="edittextarea" id="fileDesc_<#= doc.fileid #>"><#= doc.description #></p>
            <a class="removeAttachment" id="fileRemoveAttachment_<#= doc.fileid #>" href="javascript:;">Remove attachment</a>
        </div>
        <div class="clear"></div>
        <# } #>
    <# } #>
    </div>
</div>
<div id="fileUploadButton">
    Attach new files
</div>
<div class="uploadnotice"></div>
</script>
<script type="text/html" id="uploadImage">
    <div class="filesIcon">
        <a class="attachment" href="<#= url #>"><img width="75px" height="75px" src="<#= icon #>" /></a>
    </div>
    <div class="filesDescription">
        <h3 class="edittext" id="fileTitle_<#= fileid #>"><#= title #></h3>
        <p class="edittextarea" id="fileDesc_<#= fileid #>"><#= description #></p>
        <a class="removeAttachment" id="fileRemoveAttachment_<#= fileid #>" href="javascript:;">Remove attachment</a>
    </div>
    <div class="clear"></div>
</script>
<script type="text/html" id="uploadDocument">
    <div class="filesIcon">
        <a href="<#= url #>" target="_blank"><img width="32px" height="32px" src="<#= icon #>" /></a>
    </div>
    <div class="documents filesDescription">
        <h3 class="edittext" id="fileTitle_<#= fileid #>"><#= title #></h3>
        <p class="edittextarea" id="fileDesc_<#= fileid #>"><#= description #></p>
        <a class="removeAttachment" id="fileRemoveAttachment_<#= fileid #>" href="javascript:;">Remove attachment</a>
    </div>
    <div class="clear"></div>
</script>
<?php 
// !!! The code above is duplicated in workitem.inc
// !!! [END DUP]
?>
<script type="text/javascript">
var projectid = <?php echo !empty($project_id) ? $project_id : "''"; ?>;
var imageArray = new Array();
var documentsArray = new Array();
(function($) {
    // journal info accordian
    // flag to say we've not loaded anything in there yet
    
    $('#accordion').accordion( "activate" , 0 );
    $.ajax({
        type: 'post',
        url: 'jsonserver.php',
        data: {
            projectid: projectid,
            userid: user_id,
            action: 'getFilesForProject'
        },
        dataType: 'json',
        success: function(data) {
            if (data.success) {
                var images = data.data.images;
                var documents = data.data.documents;
                for (var i=0; i < images.length; i++) {
                    imageArray.push(images[i].fileid);
                }
                for (var i=0; i < documents.length; i++) {
                    documentsArray.push(documents[i].fileid);
                }
                var files = $('#uploadedFiles').parseTemplate(data.data);
                files = files + '<script type="text/javascript" src="js/uploadFiles.js"><\/script>';
                $('#uploadPanel').append(files);
                $('#accordion').accordion({
                    clearStyle: true,
                    collapsible: true
                });
            }
        }
    });
})(jQuery);
</script>
<title>Worklist | Fast pay for your work, open codebase, great community.</title>
</head>
<body>
<div style="display: none; position: fixed; top: 0px; left: 0px; width: 100%; height: 100%; text-align: center; line-height: 100%; background: white; opacity: 0.7; filter: alpha(opacity =   70); z-index: 9998"
     id="loader_img"><div id="loader_img_title"><img src="images/loading_big.gif"
     style="z-index: 9999"></div></div>

<!-- Popup for editing/adding  a work item -->
<?php require_once('dialogs/popup-edit.inc'); ?>
<!-- Popup for breakdown of fees-->
<?php require_once('dialogs/popup-fees.inc'); ?>
<!-- Popup for budget info -->
<?php require_once('dialogs/budget-expanded.inc') ?>
<!-- Popups for tables with jobs from quick links -->
<?php require_once('dialogs/popups-userstats.inc'); ?>
<!-- Popup for add project info-->
<?php require_once('dialogs/popup-addproject.inc'); ?>
<!-- Popup for  add role -->
<?php include('dialogs/popup-addrole.inc') ?>
<!-- Popup for viewing role -->
<?php include('dialogs/popup-role-info.inc') ?>
<!-- Popup for  edit role -->
<?php include('dialogs/popup-edit-role.inc') ?>
<?php
if(isset($_REQUEST['addFromJournal'])) {
?>
<div class="hidden">
<input type="submit" id="add" name="add" value="Add Job" />
</div>
<?php
} else {
    include("format.php");
?>
<!-- ---------------------- BEGIN MAIN CONTENT HERE ---------------------- -->

<!-- Head with search filters, user status, runer budget stats and quick links for the jobs-->
<?php
// krumch 20110419 Set to open Worklist from Journal
if(isset($_REQUEST['journal_query'])) {
   $filter->setProjectId($_REQUEST['project']);
   $filter->setUser($_REQUEST['user']);
}
   include("search-head.inc"); ?>
<?php 
// show project information header
if (is_object($inProject)) {
    $edit_mode = false;
    if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'edit' && $inProject->isOwner($userId)) {
        $edit_mode = true;
    }
?>
<?php if ($inProject->isOwner($userId)) : ?>
<?php if ($edit_mode) : ?>
        <span style="width: 150px; float: right;"><a href="?action=view">Switch to View Mode</a></span>
<?php else: ?>        
        <span style="width: 150px; float: right;"><a href="?action=edit">Switch to Edit Mode</a></span>
<?php endif; ?>
<?php endif; ?>
    <h2>Project:  [#<?php echo $inProject->getProjectId(); ?>] <?php echo $inProject->getName(); ?></h2>
<?php if ($edit_mode) : ?>
    <form name="project-form" id="project-form" action="<?php echo SERVER_URL . $inProject->getName(); ?>" method="post">
        <fieldset>
            <p class="info-label">Edit Description:<br />
                <textarea name="description" id="description" size="48" /><?php echo $inProject->getDescription(); ?></textarea>
            </p>
            <div>
                <input class="left-button" type="submit" id="cancel" name="cancel" value="Cancel">
                <input class="right-button" type="submit" id="save_project" name="save_project" value="Save">
            </div>
            <input type="hidden" name="project" value="<?php echo $inProject->getName(); ?>" />
        </fieldset>
    </form>
<?php endif; ?>
    <ul>
<?php if (! $edit_mode) : ?>
        <li><strong>Description:</strong> <?php echo $inProject->getDescription(); ?></li>
<?php endif; ?>
        <li><strong>Budget:</strong> $<?php echo $inProject->getBudget(); ?></li>
        <li><strong>Contact Info:</strong> <?php echo $inProject->getContactInfo(); ?></li>
<?php if ($inProject->getRepository() != '') : ?>
        <li><strong>Repository:</strong> <a href="<?php echo $inProject->getRepoUrl(); ?>"><?php echo $inProject->getRepoUrl(); ?></a></li>
<?php else: ?>
        <li><strong>Repository:</strong> </li>
<?php endif; ?>
    </ul>
    <h3>Jobs:</h3>
<div><div class="projectLeft">
<?php } ?>
<table class="table-worklist">
    <thead>
        <tr class="table-hdng">
            <?php if (! $hide_project_column) echo '<td class="clickable">Project</td>'; ?>
            <td><span class="clickable">ID</span> - <span class="clickable">Summary</span></td>
            <?php if (! $hide_project_column) echo '<td class="clickable">Status</td>'; ?>
            <?php if (! $hide_project_column) echo '<td class="clickable">Who</td>'; ?>
            <?php if (! $hide_project_column) echo '<td class="clickable" id="defaultsort">When</td>'; ?>
            <?php if (! $hide_project_column) echo '<td class="clickable" style="min-width:80px">Comments</td>'; ?>
            <?php if (! $hide_project_column) {
                echo '<td class="worklist-fees clickable"';
                echo (empty($_SESSION['is_runner'])) ? ' style="display:none"' : ''; 
                echo '>Fees/Bids</td>';
            } ?>
        </tr>
    </thead>
    <tbody>
    </tbody>
</table>
<?php if (is_object($inProject)) { ?>
</div>
<div class="projectRight">
<!-- table for roles <mikewasmike 15-ju-2011>  -->
<?php if ($inProject->isOwner($userId)) : ?>
            <div id="for_view">
                <div class="roles">
                    <div id="roles-panel">
                        <table width="100%" class="table-bids">
                            <caption class="table-caption" >
                                <b>Roles</b>
                            </caption>
                            <thead>
                                <tr class="table-hdng">
                                    <td>Title</td>
                                    <td>%</td>
                                    <td>Min. Amount</td>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if(empty($roles)) { ?>
                                <tr>
                                    <td style="text-align: center;" colspan="4">No roles added.</td>
                                </tr>
                            <?php } else { $row = 1;
                                foreach($roles as $role) { ?>
                                <tr class="row-role-list-live
                                    <?php ($row % 2) ? print 'rowodd' : print 'roweven'; $row++; ?> roleitem<?php
                                         echo '-'.$role['id'];?>">
                                        <script type="data"><?php echo "{id: '{$role['id']}', role_title: '{$role['role_title']}', percentage: '{$role['percentage']}', min_amount: '{$role['min_amount']}'}" ?></script>
                                    <td ><?php echo $role['role_title'];?></td>
                                    <td ><?php echo $role['percentage'];?></td>
                                    <td ><?php echo $role['min_amount'];?></td>
                                </tr>
                                <?php } ?>
                            <?php } ?>
                            </tbody>
                        </table>
                        <div class="buttonContainer">
                            <input type="submit" value="Add Role" onClick="return showAddRoleForm('bid');" />
                        </div>

                    </div>
                </div>
            </div>
<?php endif; ?>
<!--End of roles table-->

<div id="uploadPanel"> </div>
</div>
<div class="clear">&nbsp;</div>
</div>
<?php } ?>

<span id="direction" style="display: none; float: right;"><img src="images/arrow-up.png" /></span>
<div id="user-info" title="User Info"></div>
<script type="text/javascript">
GetStatus('worklist');
</script>

<?php
    include("footer.php");
}
?>