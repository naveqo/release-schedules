<?php

require_once('Config.php');

/***********************************
 * PivotalTracker Webhooks
***********************************/
if($_SERVER["REQUEST_METHOD"] == "POST") {
  //V5が生POSTデータ
  $raw = file_get_contents("php://input");
  $webhooks_data = json_decode($raw);

  //hookした時のアクションの種類
  $hook_kind = $webhooks_data->kind;

  //プロジェクトID
  $project_id = $webhooks_data->project->id;

  //ストーリーID
  $sorty_id = $webhooks_data->primary_resources[0]->id;

  //APIパス
  $format_strings = "https://www.pivotaltracker.com/services/v5/projects/%s/stories/%s";
  $pivotal_api_path = sprintf($format_strings, $project_id, $sorty_id);

  //ストーリータイプ
  $sorty_type = $webhooks_data->primary_resources[0]->story_type;

  //ストーリーの名前
  $sorty_name = $webhooks_data->primary_resources[0]->name;

  //ストーリーのURL
  $sorty_url = $webhooks_data->primary_resources[0]->url;

  //変更する前のリリース日
  $deadline_before = date("Y-m-d",$webhooks_data->changes[0]->original_values->deadline / 1000);

  //変更した後のリリース日
  $deadline_after = date("Y-m-d",$webhooks_data->changes[0]->new_values->deadline / 1000);
}

  /*
  * PivotalTracker webhooksのレスポンスだけだとdiscription内容が取得出来ないので別途でAPI叩く必要がある
  * discription内容にカレンダーイベントIDを埋め込んで、pivotal側の日時変更時に連動出来るようなっている
  */
if (isset($raw)) {
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

  //該当storyのdiscription
  $pt_description = $pt_api_data->description;

  //discriptionに記載しているカレンダーイベントIDを取得
  preg_match('/calevent_id:([-_a-zA-Z0-9]*)/', $pt_api_data->description, $matches);
  $cal_event_id = $matches[1];

  //deadline判定
  $has_deadline = isset($pt_api_data->deadline);
}


/***********************************
 * Google Calender API
***********************************/
if($_SERVER["REQUEST_METHOD"] == "POST") {
  require_once '/home/sites/develop.media-craft.jp/web/tool/webhooks/release-schedules/google-api-php-client-1-master/src/Google/autoload.php';

  // 秘密キーファイルの読み込み
  $private_key = @file_get_contents('./pivotaltracker-calender-19e8007ccc94.p12');

  // GoogleクライアントとCalenderのインスタンス作成
    if( isset( $_SESSION['service_token'] ) )
    {
      $client->setAccessToken( $_SESSION['service_token'] ) ;
    }

    $scopes = array( 'https://www.googleapis.com/auth/calendar' ) ;

    $credentials = new Google_Auth_AssertionCredentials( CALENDER_CLIENT_ID , $scopes , $private_key ) ;

    $client = new Google_Client() ;
    $client->setAssertionCredentials( $credentials ) ;

    if( $client->getAuth()->isAccessTokenExpired() )
    {
      $client->getAuth()->refreshTokenWithAssertion( $credentials ) ;
    }

    $_SESSION['service_token'] = $client->getAccessToken() ;

    $service = new Google_Service_Calendar( $client ) ;

  //PivotalTrackerのSTORY TYPEがreleaseの時だけ変更
  if ($sorty_type == "release") {

    if ($cal_event_id == "unissued" && $has_deadline) { /* insert */

      $addEvent = new Google_Service_Calendar_Event(array(
        'summary' => $sorty_name,
        'description' => $sorty_url,
        'start' => array(
          'date' => $deadline_after
        ),
        'end' => array(
          'date' => $deadline_after
        )
      ));

      //新規カレンダーイベント作成
      $eventsRes = $service->events->insert(CALENDER_ID, $addEvent);

      //カレンダーイベントID取得
      $eventId = $eventsRes->id;

      //カレンダーイベントIDが埋め込まれたpivotalのディスクリプション
      $replaced_pt_discription = str_replace("calevent_id:unissued", "calevent_id:" . $eventId, $pt_description);

      // PUTパラメータ
      $params = array(
        'description' => $replaced_pt_discription,
      );

      // PivotalTracker discriptionのカレンダーイベントIDを発行されたIDに変更
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

      $log_text = "リリース日が決定致しました。";

    } elseif ($has_deadline != 1) { /* delete */

      //既存カレンダーイベントの削除
      $service->events->delete(CALENDER_ID, $cal_event_id);

      //discriptionのカレンダーイベントIDを未発行の状態の内容に置換
      $replaced_default_id_pt_discription = preg_replace('/calevent_id:([-_a-zA-Z0-9]*)/', "calevent_id:unissued", $pt_description);

      // PUTパラメータ
      $params = array(
        'description' => $replaced_default_id_pt_discription,
      );

      // PivotalTracker discriptionのカレンダーイベントIDを未発行状態に変更
      $pivotal_delete_options = array(
        CURLOPT_URL => $pivotal_api_path,
        CURLOPT_HTTPHEADER => array("X-TrackerToken: " . PIVOTAL_API_TOKEN),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "PUT",
        CURLOPT_POSTFIELDS => http_build_query($params, '', '&')
      );

      $pt_delete_ch = curl_init();
      curl_setopt_array($pt_delete_ch, $pivotal_delete_options);
      curl_exec($pt_delete_ch);
      curl_close($pt_delete_ch);

      $log_text = "リリース日が削除されました。";

    } elseif ($has_deadline && $deadline_before != $deadline_after) { /* patch */

      $optParams = new Google_Service_Calendar_Event(array(
        'start' => array(
          'date' => $deadline_after
        ),
        'end' => array(
          'date' => $deadline_after
        )
      ));

      //既存カレンダーイベントの日付変更
      $service->events->patch(CALENDER_ID, $cal_event_id, $optParams);
      $log_text = "リリース日が更新されました。";
    }
  }
}

/***********************************
 * log
***********************************/
if($_SERVER["REQUEST_METHOD"] == "POST") {
  if($hook_kind == "story_update_activity" && $sorty_type == "release" && $log_text != ""){

$body = "";
$body = <<<EOD
{$log_text}

{$sorty_name}
{$sorty_url}
******************
{$raw}
******************
EOD;

    $params = array(
      'body' => $body,
      'to_ids' => $to_id
    );

    $cw_options = array(
      CURLOPT_URL => "https://api.chatwork.com/v1/rooms/{$room_id}/messages",
      CURLOPT_HTTPHEADER => array("X-ChatWorkToken: " . CHATWORK_API_TOKEN),
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => http_build_query($params, '', '&')
    );

    $ch = curl_init();
    curl_setopt_array($ch, $cw_options);
    $response = curl_exec($ch);
    curl_close($ch);

  }
}
