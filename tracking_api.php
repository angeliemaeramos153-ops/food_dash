<?php
session_start();
require_once 'db_connect.php';
require_once 'notification_lib.php';
header('Content-Type: application/json; charset=utf-8');

function out($ok, $message='', $data=[], $code=200){ http_response_code($code); echo json_encode(array_merge(['success'=>$ok,'message'=>$message],$data)); exit; }
if(!isset($_SESSION['user_id'],$_SESSION['role'])) out(false,'Session expired.',[],401);
$role=strtolower($_SESSION['role']); $uid=(int)$_SESSION['user_id'];
function distance_km($lat1,$lon1,$lat2,$lon2){
    $r=6371.0088; $p=pi()/180; $dlat=($lat2-$lat1)*$p; $dlon=($lon2-$lon1)*$p;
    $a=sin($dlat/2)**2 + cos($lat1*$p)*cos($lat2*$p)*sin($dlon/2)**2;
    return $r*2*asin(min(1,sqrt($a)));
}
function valid_lat_lng($lat,$lng){ return is_numeric($lat)&&is_numeric($lng)&&$lat>=-90&&$lat<=90&&$lng>=-180&&$lng<=180; }

if($_SERVER['REQUEST_METHOD']==='POST'){
    $payload=json_decode(file_get_contents('php://input'),true); if(!is_array($payload)) $payload=$_POST;
    $action=$payload['action']??''; $order_id=(int)($payload['order_id']??0);
    if(!valid_lat_lng($payload['lat']??null,$payload['lng']??null)) out(false,'Invalid location coordinates.',[],422);
    $lat=(float)$payload['lat']; $lng=(float)$payload['lng'];

    if($action==='update_rider_location'){
        if($role!=='rider') out(false,'Only Riders can send live location.',[],403);
        $stmt=$conn->prepare("SELECT order_id, customer_id, order_ref, rider_id, status FROM food_orders WHERE order_id=? AND rider_id IS NOT NULL LIMIT 1");
        $stmt->bind_param('i',$order_id); $stmt->execute(); $o=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if(!$o || (int)$o['rider_id']!==$uid){
            // Existing project maps login users to the riders table. Resolve the rider id by name if needed.
            $name=$_SESSION['full_name']??''; $q=$conn->prepare("SELECT rider_id FROM riders WHERE rider_name=? LIMIT 1"); $q->bind_param('s',$name); $q->execute(); $rr=$q->get_result()->fetch_assoc(); $q->close();
            if(!$rr || !$o || (int)$o['rider_id']!==(int)$rr['rider_id']) out(false,'This delivery is not assigned to this Rider.',[],403);
        }
        $stmt=$conn->prepare("UPDATE food_orders SET rider_lat=?, rider_lng=?, location_updated_at=NOW() WHERE order_id=?");
        $stmt->bind_param('ddi',$lat,$lng,$order_id); $stmt->execute(); $stmt->close();
        // Check proximity immediately so the notification can be automatic even before the Customer opens the tracker.
        $stmt=$conn->prepare("SELECT customer_id, order_ref, customer_lat, customer_lng, delivery_status, near_notified_at FROM food_orders WHERE order_id=? LIMIT 1");
        $stmt->bind_param('i',$order_id); $stmt->execute(); $fresh=$stmt->get_result()->fetch_assoc(); $stmt->close();
        $distance=null; $near=false;
        if($fresh && $fresh['customer_lat']!==null && $fresh['customer_lng']!==null){
            $distance=distance_km((float)$fresh['customer_lat'],(float)$fresh['customer_lng'],$lat,$lng); $near=$distance<=0.5;
            if($near && empty($fresh['near_notified_at']) && in_array($fresh['delivery_status'],['on_the_way','near_customer'],true)){
                $conn->begin_transaction();
                try{
                    $u=$conn->prepare("UPDATE food_orders SET delivery_status='near_customer', status='near_customer', near_notified_at=NOW(), updated_at=NOW() WHERE order_id=? AND near_notified_at IS NULL");
                    $u->bind_param('i',$order_id); $u->execute(); $changed=$u->affected_rows; $u->close();
                    if($changed) create_notification($conn,(int)$fresh['customer_id'],'rider_near','Your rider is near','Your rider is within about 500 meters of your delivery location. Please get ready for '.$fresh['order_ref'].'.',$order_id);
                    $conn->commit();
                }catch(Throwable $e){$conn->rollback();}
            }
        }
        out(true,'Location updated.',['lat'=>$lat,'lng'=>$lng,'distance_km'=>$distance,'near'=>$near]);
    }

    if($action==='update_customer_location'){
        if($role!=='customer') out(false,'Only Customers can set a delivery location.',[],403);
        if($order_id>0){
            $stmt=$conn->prepare("UPDATE food_orders SET customer_lat=?, customer_lng=? WHERE order_id=? AND customer_id=?");
            $stmt->bind_param('ddii',$lat,$lng,$order_id,$uid);
        }else{
            $stmt=$conn->prepare("UPDATE food_orders SET customer_lat=?, customer_lng=? WHERE customer_id=? AND status NOT IN ('delivered','cancelled')");
            $stmt->bind_param('ddi',$lat,$lng,$uid);
        }
        $stmt->execute(); $changed=$stmt->affected_rows; $stmt->close(); out(true,'Delivery location saved.',['updated'=>$changed]);
    }
    out(false,'Unknown tracking action.',[],400);
}

if($_SERVER['REQUEST_METHOD']==='GET' && ($_GET['action']??'')==='track'){
    if($role!=='customer') out(false,'Only Customers can track orders.',[],403);
    $order_id=(int)($_GET['order_id']??0); if($order_id<=0) out(false,'Order ID is required.',[],422);
    $stmt=$conn->prepare("SELECT order_id,order_ref,customer_name,store_name,customer_lat,customer_lng,rider_id,rider_lat,rider_lng,location_updated_at,status,delivery_status,near_notified_at FROM food_orders WHERE order_id=? AND customer_id=? LIMIT 1");
    $stmt->bind_param('ii',$order_id,$uid); $stmt->execute(); $o=$stmt->get_result()->fetch_assoc(); $stmt->close(); if(!$o) out(false,'Order not found.',[],404);
    $distance=null; $near=false;
    if($o['customer_lat']!==null && $o['customer_lng']!==null && $o['rider_lat']!==null && $o['rider_lng']!==null){
        $distance=distance_km((float)$o['customer_lat'],(float)$o['customer_lng'],(float)$o['rider_lat'],(float)$o['rider_lng']); $near=$distance<=0.5;
        if($near && empty($o['near_notified_at']) && in_array($o['delivery_status'],['on_the_way','near_customer'],true)){
            $conn->begin_transaction();
            try{
                $u=$conn->prepare("UPDATE food_orders SET delivery_status='near_customer', status='near_customer', near_notified_at=NOW(), updated_at=NOW() WHERE order_id=? AND near_notified_at IS NULL"); $u->bind_param('i',$order_id); $u->execute(); $changed=$u->affected_rows; $u->close();
                if($changed) create_notification($conn,$uid,'rider_near','Your rider is near','Your rider is within about 500 meters of your delivery location. Please get ready for '.$o['order_ref'].'.',$order_id);
                $conn->commit(); if($changed){$o['status']='near_customer';$o['delivery_status']='near_customer';}
            }catch(Throwable $e){$conn->rollback();}
        }
    }
    out(true,'',['order'=>$o,'distance_km'=>$distance,'near'=>$near,'near_threshold_km'=>0.5]);
}
out(false,'Invalid tracking request.',[],400);
?>