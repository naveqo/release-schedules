<?php
if($_SERVER["REQUEST_METHOD"] == "POST") {

  require_once('Config.php');

  $data = json_decode($_POST['payload'],true);

  $comment = $data["comment"]["body"];

  $action = $data["action"];

  //フォーマット：「リリース日は2016-05-23です。\r\n[ptsid:119881767]」
  $format = '/##\s:calendar:.+(\d{4}(?:-|\/)\d{1,2}(?:-|\/)\d{1,2}).+\\r\\n\[ptsid:(\d*)\]/';
  preg_match($format, $comment, $m);
  $release_date = $m[1];
  $release_date = $release_date . "T00:00:00Z";
  $story_id = $m[2];

/***********************************
 * PivotalTracker API
***********************************/
  /**
  * リクエスト時にPivotalTrackerの状態を知る為にGETして情報を得る
  */
  $format_strings = "https://www.pivotaltracker.com/services/v5/projects/%s/stories/%s";
  $pivotal_api_path = sprintf($format_strings, $project_id_for_githooks, $story_id);

  $pivotal_options = array(
    CURLOPT_URL => $pivotal_api_path,
    CURLOPT_HTTPHEADER => array("X-TrackerToken: " . PIVOTAL_API_TOKEN),
    CURLOPT_RETURNTRANSFER => true
  );

  $pt_ch = curl_init();
  curl_setopt_array($pt_ch, $pivotal_options);
  $pt_api_response = curl_exec($pt_ch);
  $pt_api_data = json_decode($pt_api_response);
  curl_close($pt_ch);

  //ストーリーID
  $story_type = $pt_api_data->story_type;

  //Githubコメントでリリース日とPivotalTrackerのIDが記載されていた場合
  if (isset($release_date) && isset($story_id)) {

    /**
    * PivotalTrackerのSTORYがfeatureだった場合はreleaseに変更してから日付を追加する
    */
    if ($story_type == "feature") {

      //unstartedになってないとreleaseへの変更を受け付けない
      $params = array(
        'current_state' => "unstarted"
      );

      $pivotal_put_options = array(
        CURLOPT_URL => $pivotal_api_path,
        CURLOPT_HTTPHEADER => array("X-TrackerToken: " . PIVOTAL_API_TOKEN),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "PUT",
        CURLOPT_POSTFIELDS => http_build_query($params, '', '&')
      );

      $pt_put_ch = curl_init();

      curl_setopt_array($pt_put_ch, $pivotal_put_options);
      curl_exec($pt_put_ch);

      //リリースに変更する
      $params = array(
        'story_type' => "release"
      );

      $pivotal_put_options = array(
        CURLOPT_URL => $pivotal_api_path,
        CURLOPT_HTTPHEADER => array("X-TrackerToken: " . PIVOTAL_API_TOKEN),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "PUT",
        CURLOPT_POSTFIELDS => http_build_query($params, '', '&')
      );

      curl_setopt_array($pt_put_ch, $pivotal_put_options);
      curl_exec($pt_put_ch);

      //日付を更新する
      $params = array(
        'deadline' => $release_date
      );

      $pivotal_put_options = array(
        CURLOPT_URL => $pivotal_api_path,
        CURLOPT_HTTPHEADER => array("X-TrackerToken: " . PIVOTAL_API_TOKEN),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "PUT",
        CURLOPT_POSTFIELDS => http_build_query($params, '', '&')
      );

      curl_setopt_array($pt_put_ch, $pivotal_put_options);
      curl_exec($pt_put_ch);

      curl_close($pt_put_ch);

    /**
    * PivotalTrackerのSTORYがreleaseだった場合は日付だけ変更する
    */
    } elseif ($story_type == "release" && $action != "deleted") {

      $params = array(
        'deadline' => $release_date
      );

      $pivotal_put_options = array(
        CURLOPT_URL => $pivotal_api_path,
        CURLOPT_HTTPHEADER => array("X-TrackerToken: " . PIVOTAL_API_TOKEN),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "PUT",
        CURLOPT_POSTFIELDS => http_build_query($params, '', '&')
      );

      $pt_put_ch = curl_init();
      curl_setopt_array($pt_put_ch, $pivotal_put_options);
      curl_exec($pt_put_ch);
      curl_close($pt_put_ch);

    }

    /**
    * ptsidがコメントから削除された場合はPivotalTrackerの日付を解除する
    */
    if ($action == "deleted") {

      // release状態の時にdeadlineをnullでPUT出来ないので一旦featureに戻し再度releaseに変更する
      $params = array(
        'story_type' => "feature"
      );

      $pivotal_put_options = array(
        CURLOPT_URL => $pivotal_api_path,
        CURLOPT_HTTPHEADER => array("X-TrackerToken: " . PIVOTAL_API_TOKEN),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "PUT",
        CURLOPT_POSTFIELDS => http_build_query($params, '', '&')
      );

      $pt_put_ch = curl_init();

      curl_setopt_array($pt_put_ch, $pivotal_put_options);
      curl_exec($pt_put_ch);

      // releaseに戻す → Googleカレンダーからイベントを削除する
      $params = array(
        'story_type' => "release"
      );

      $pivotal_put_options = array(
        CURLOPT_URL => $pivotal_api_path,
        CURLOPT_HTTPHEADER => array("X-TrackerToken: " . PIVOTAL_API_TOKEN),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "PUT",
        CURLOPT_POSTFIELDS => http_build_query($params, '', '&')
      );

      curl_setopt_array($pt_put_ch, $pivotal_put_options);
      curl_exec($pt_put_ch);

      curl_close($pt_put_ch);

    }//if ($action == "deleted")

  }//if (isset($release_date) && isset($story_id))

}//if($_SERVER["REQUEST_METHOD"] == "POST")

/***********************************
 * log
***********************************/
/*

$log_room_id = "********";
$log_to_id = "********";

$body = "";
$body = <<<EOD
test
EOD;

$params = array(
  'body' => $body,
  'to_ids' => $log_to_id
);

$cw_options = array(
  CURLOPT_URL => "https://api.chatwork.com/v1/rooms/{$log_room_id}/messages",
  CURLOPT_HTTPHEADER => array("X-ChatWorkToken: " . CHATWORK_API_TOKEN),
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => http_build_query($params, '', '&')
);

$ch = curl_init();
curl_setopt_array($ch, $cw_options);
curl_exec($ch);
curl_close($ch);
*/


