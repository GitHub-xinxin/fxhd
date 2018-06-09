<?php
/**
 * 分销活动商城定义
 *
 * @author ly_dicyan
 * @url http://bbs.we7.cc/
 */
defined('IN_IA') or exit('Access Denied');

class Ly_fenxiaohuodongModuleSite extends WeModuleSite {
	public function __construct(){
		global $_W,$_GPC;
		if(empty($_GPC['artid'])){
			//无效访问
		}
		if(!empty($_W['openid'])){
			$user=pdo_get("ly_fxhd_users",['openid'=>$_W['openid']]);
			if(empty($user)){
			//如果为空。就插入
				pdo_insert("ly_fxhd_users",[
					"openid"=>$_W['openid'],
					"uniacid"=>$_W['uniacid'],
					"insert_time"=>time()
				]);
			}else{
			//如果不为空。就忽略
			}
			/**
			 * 录入上下级关系
			 */
			$user_id = pdo_get("ly_fxhd_users",['openid'=>$_W['openid']])['id'];
			$parentid = pdo_get("ly_fxhd_users",array('uniacid'=>$_W['uniacid'],'id'=>$_GPC['parentid']))['id'];
			if($user_id != $parentid){
				if(!empty($parentid)){
					//是否已经存在
					$is_has = pdo_get('ly_fxhd_superior',array('artid'=>$_GPC['artid'],'userid'=>$user_id));
					if(empty($is_has)){
						pdo_insert('ly_fxhd_superior',array('artid'=>$_GPC['artid'],'fatherid'=>$parentid,'userid'=>$user_id,'insert_time'=>time()));
					}
				}
			}	
		}
	}
	/**
	 * 支付
	 */
	public function doMobilePayment(){
		global $_W,$_GPC;
		/**
		 * 用户信息
		 */
		$userid = pdo_get('ly_fxhd_users',array('openid'=>$_W['openid'],'uniacid'=>$_W['uniacid']))['id'];
		$shop_id =pdo_get('ly_fxhd_activity',array('uniacid'=>$_W['uniacid'],'id'=>$_GPC['act_id']))['goodid'];
		/**
		 *  订单参数
		 */
		$pay_agr = array(
			'uniacid'=>$_W['uniacid'],
			'userid'=>$userid,
			'activityid'=>$_GPC['act_id'],
			'type'=>0,
			'goodid'=>$shop_id,
			'insert_time'=>time(),
			'price'=>$_GPC['price'],
			'ticket'=>'order_'.date('YmdHis').random(10, 1)//随机订单号
		);
		$res=pdo_insert('ly_fxhd_orders',$pay_agr);
		if(!empty($res)){
			$params = array(					
				'tid' => $pay_agr['ticket'],					
				'ordersn' => $pay_agr['ticket'],					
				'title' => '支付订单',					
				'fee' => $pay_agr['price']				
			);	
			$this->pay($params);//支付主函数
			exit;
		}else{
			message('支付参数错误','','error');
		}
	}
	/**
	 * 支付回调
	 */
	public function payResult($params){
		global $_W,$_GPC;
		load()->func('logging');
		if ($params['result'] == 'success' && $params['from'] == 'notify') {
			/**
			 * 支付成功后,先更新订单表支付状态
			 */
			$modif_order_status = pdo_update('ly_fxhd_orders',array('type'=>1,'pay_time'=>time()),array('uniacid'=>$_W['uniacid'],'ticket'=>$params['tid']));
			if(empty($modif_order_status)){
				logging_run('更新订单支付状态失败,ticket=='.$params['tid'],'info','fxhd_status');
			}else{
				//查找购买人
				$order_info = pdo_get('ly_fxhd_orders',array('uniacid'=>$_W['uniacid'],'ticket'=>$params['tid'],'type'=>1));
				if(empty($order_info)){
					logging_run('查找订单失败,ticket=='.$params['tid'],'info','fxhd_order');
				}else{
					//查找活动信息
					$art_info = pdo_get('ly_fxhd_activity',array('id'=>$order_info['activityid'],'uniacid'=>$_W['uniacid']));
					if(empty($art_info)){
						logging_run('查找活动失败,ticket=='.$params['tid'],'info','fxhd_activity');
					}else{
						//更新一级返利 上级奖金数
						$one_tier = pdo_get('ly_fxhd_superior',array('artid'=>$order_info['activityid'],'userid'=>$order_info['userid']));
						if(empty($one_tier)){
							logging_run('查找上级失败,ticket=='.$params['tid'],'info','fxhd_rebate');
						}else{
							if(!empty($art_info['first_level'])){
								$one_pay_result = pdo_update('ly_fxhd_users',array('bonus +='=>$params['fee'] * $art_info['first_level']),array('id'=>$one_tier['fatherid'],'uniacid'=>$_W['uniacid']));
								if(empty($one_pay_result)){
									logging_run('更新一级返利失败,ticket=='.$params['tid'],'info','fxhd_rebate');
								}else{
									//录入返利表
									pdo_insert('ly_fxhd_rebate',array('uniacid'=>$_W['uniacid'],'act_id'=>$art_info['id'],'user_id'=>$one_tier['fatherid'],'sub_id'=>$order_info['userid'],'fee'=>$params['fee'] * $art_info['first_level'],'insert_time'=>time()));
								}
							}
							//更新二级返利	上级奖金数	
							$two_tier = pdo_get('ly_fxhd_superior',array('artid'=>$order_info['activityid'],'userid'=>$one_tier['fatherid']));
							if(empty($two_tier)){
								logging_run('查找上级的上级失败,ticket=='.$params['tid'],'info','fxhd_rebate');
							}else{
								if(!empty($art_info['second_level'])){
									$two_pay_result = pdo_update('ly_fxhd_users',array('bonus +='=>$params['fee'] * $art_info['second_level']),array('id'=>$two_tier['fatherid'],'uniacid'=>$_W['uniacid']));
									if(empty($two_pay_result)){
										logging_run('更新二级返利失败,ticket=='.$params['tid'],'info','fxhd_rebate');
									}else{
										//录入返利表
										pdo_insert('ly_fxhd_rebate',array('uniacid'=>$_W['uniacid'],'act_id'=>$art_info['id'],'user_id'=>$two_tier['fatherid'],'sub_id'=>$one_tier['userid'],'fee'=>$params['fee'] * $art_info['second_level'],'insert_time'=>time()));
									}
								}	
								//更新三级返利  上级金额数
								$three_tier = pdo_get('ly_fxhd_superior',array('artid'=>$order_info['activityid'],'userid'=>$two_tier['fatherid']));
								if(empty($three_tier)){
									logging_run('查找上级的上级的上级失败,ticket=='.$params['tid'],'info','fxhd_rebate');
								}else{
									if(!empty($art_info['three_level'])){
										$three_pay_result = pdo_update('ly_fxhd_users',array('bonus +='=>$params['fee'] * $art_info['three_level']),array('id'=>$three_tier['fatherid'],'uniacid'=>$_W['uniacid']));
										if(empty($three_pay_result)){
											logging_run('更新三级返利失败,ticket=='.$params['tid'],'info','fxhd_rebate');
										}else{
											//录入返利表
											pdo_insert('ly_fxhd_rebate',array('uniacid'=>$_W['uniacid'],'act_id'=>$art_info['id'],'user_id'=>$three_tier['fatherid'],'sub_id'=>$two_tier['userid'],'fee'=>$params['fee'] * $art_info['three_level'],'insert_time'=>time()));
										}
									}
								}	
							}
						}				
					}
				}
			}
		}
		if ($params['from'] == 'return') {
			if ($params['result'] == 'success') {	
				$order_info = pdo_get('ly_fxhd_orders',array('uniacid'=>$_W['uniacid'],'ticket'=>$params['tid']));
				$one_tier = pdo_get('ly_fxhd_superior',array('artid'=>$order_info['activityid'],'userid'=>$order_info['userid']));
				message('支付成功！',$this->createMobileUrl('art',array('artid'=>$order_info['activityid'],'parentid'=>$one_tier['fatherid'])),'success');
			}else{
				message('支付失败！','', 'error');
			}
		}
	}
	public function doWebShops(){
		global $_W,$_GPC;
		$thisid=$_GPC['sid'];
		//判断删除
		if($_GPC['shanchu']==1&&!empty($_GPC['sid'])){
			$result = pdo_delete('ly_fxhd_shops', array('id' =>$_GPC['sid']));
			if(!empty($result)){
				message("删除成功！",$this->createWebUrl('shops'),"success");
			}else{
				message("删除出现问题","","error");
			}
		}
		$thistype;
		//就根据这个分辨类型了。3个状态，0列表，1插入，2更新
		if(empty($thisid)){
			if($_GPC['bainji']==1){
				$thistype=2;
			}else{
				$thistype=0;
			}
		}else{
			if($thisid==-1){
				$thistype=1;
			}elseif($thisid>=0){
				$thistype=2;
			}
		}
		if($thistype==2){
			$oneshop=pdo_get("ly_fxhd_shops",array("id"=>$_GPC['sid']));
			if(empty($oneshop)){
				message("无此数据！","","error");
			}else{}
		}
		if($thistype==0){
			$shops=pdo_getall("ly_fxhd_shops",array("uniacid"=>$_W['uniacid']));
		}
		if($_W['ispost']){
			if($thistype==1){
				$dbdata=[
					"name"=>$_GPC['name'],
					"uniacid"=>$_W['uniacid'],
					"username"=>$_GPC['username'],
					"phone"=>$_GPC['phone'],
					"address"=>$_GPC['address'],
					"introduce"=>$_GPC['introduce']
				];
				$res=pdo_insert("ly_fxhd_shops",$dbdata);
				if(!empty($res)){
					message("插入成功",$this->createWebUrl('shops'),"success");
				}else{
					message("插入出错","","error");
				}
			}elseif ($thistype==2) {
				$dbdata=[
					"name"=>$_GPC['name'],
					"uniacid"=>$_W['uniacid'],
					"username"=>$_GPC['username'],
					"phone"=>$_GPC['phone'],
					"address"=>$_GPC['address'],
					"introduce"=>$_GPC['introduce']
				];
				$res=pdo_update("ly_fxhd_shops",$dbdata,['id'=>$_GPC['sid']]);
				if(!empty($res)){
					message("更新成功",$this->createWebUrl('shops'),"success");
				}else{
					message("更新出错","","error");
				}
			}
			
		}

		include $this->template("shops");
	}
	public function doWebInfo(){
		global $_W,$_GPC;	
		$infos=pdo_getall("ly_fxhd_infos",['uniacid'=>$_W['uniacid']]);
		include $this->template("info");

	}
	public function doWebProduct(){
		//产品
		global $_W,$_GPC;
		$thisid=$_GPC['gid'];
		//判断删除
		if($_GPC['shanchu']==1&&!empty($_GPC['gid'])){
			$result = pdo_delete('ly_fxhd_goods', array('id' =>$_GPC['gid']));
			if(!empty($result)){
				message("删除成功！",$this->createWebUrl('product',array("gid"=>$thisid)),"success");
			}else{
				message("删除出现问题",$this->createWebUrl('product',array("gid"=>$thisid)),"error");
			}
		}
		$thistype;
		//就根据这个分辨类型了。3个状态，0列表，1插入，2更新
		if(empty($thisid)){
			if($_GPC['bainji']==1){
				$thistype=2;
			}else{
				$thistype=0;
			}
		}else{
			if($thisid==-1){
				$thistype=1;
			}elseif($thisid>=0){
				$thistype=2;
			}
		}
		if($thistype==2){
			//查询出类型
			$activity=pdo_getall("ly_fxhd_category",array("uniacid"=>$_W['uniacid']));
			//查询出所有店铺
			$shops=pdo_getall("ly_fxhd_shops",array("uniacid"=>$_W['uniacid']));
			//把这个处理为一个数组key为id，val为name
			$activityarr=[];
			foreach ($activity as $k => $v) {
				$activityarr[$v['id']]=$v['name'];
			}
			$onec=pdo_get('ly_fxhd_goods',array("id"=>$thisid));
		}
		if($thistype==1){
			//查询出类型
			$activity=pdo_getall("ly_fxhd_category",array("uniacid"=>$_W['uniacid']));
			//查询出所有店铺
			$shops=pdo_getall("ly_fxhd_shops",array("uniacid"=>$_W['uniacid']));
			//把这个处理为一个数组key为id，val为name
			$activityarr=[];
			foreach ($activity as $k => $v) {$activityarr[$v['id']]=$v['name'];}
		}
		if($thistype==0){
			$sqla='SELECT a.id as aid,g.id as gid,g.name,g.banner,g.price,a.stock,a.start_time,a.end_time,a.already_peoples,a.participants
			FROM  ims_ly_fxhd_goods as g LEFT JOIN  ims_ly_fxhd_activity as a ON g.id=a.goodid WHERE g.uniacid='.$_W['uniacid'];
			$goods=pdo_fetchall($sqla);
		}
		if($_W['ispost']){
			$sid=$_GPC['shops'];
			if($thistype==1){
				$dbdata=[
					"name"=>$_GPC['name'],
					"shopid"=>$sid,
					"category"=>$_GPC['category'],
					"banner"=>$_GPC['banner'],
					"insert_time"=>time(),
					"details"=>$_GPC['details'],
					"uniacid"=>$_W['uniacid']
				];
				$res=pdo_insert("ly_fxhd_goods",$dbdata);
				if(!empty($res)){
					message("插入成功",$this->createWebUrl('product'),"success");
				}else{
					message("插入出错","","error");
				}
			}elseif ($thistype==2) {
				$dbdata=[
					"name"=>$_GPC['name'],
					"shopid"=>$sid,
					"category"=>$_GPC['category'],
					"banner"=>$_GPC['banner'],
					"insert_time"=>time(),
					"details"=>$_GPC['details'],
					"uniacid"=>$_W['uniacid']
				];
				$res=pdo_update("ly_fxhd_goods",$dbdata,['id'=>$thisid]);
				if(!empty($res)){
					message("更新成功",$this->createWebUrl('product'),"success");
				}else{
					message("更新出错",$this->createWebUrl('product'),"error");
				}
			}
		}
		include $this->template("product");
	}

	public function doWebActivity(){
		load()->func('tpl');
		global $_W,$_GPC;
		//一个商品只能对应一个活动。所以先判断是否有正在进行的活动。如果有，就提示，需要先删除正在进行的活动。
		//先判断有没有商品id，没有就推出。
		if(empty($_GPC['gid'])){
			message("没有对应的商品！","","error");
		}else{
			$goods=pdo_get("ly_fxhd_goods",array('id'=>$_GPC['gid']));
			if(empty($goods)){
				message("没有对应的商品！","","error");
			}else{
				//有对应的商品之后，查询是否有没有活动。
				$oneactivity=pdo_get("ly_fxhd_activity",array("goodid"=>$_GPC['gid']));
				if(empty($oneactivity)){
					$oneactivity['start_time']=time();
					$oneactivity['end_time']=time();
				}else{

				}
			}
			if($_W['ispost']){

			
				$dabdata=array(
					"uniacid"=>$_W['uniacid'],
					"goodid"=>$_GPC['gid'],
					"title"=>$_GPC['title'],
					"invite"=>$_GPC['invite'],
					"introduce"=>$_GPC['introduce'],
					"start_time"=>strtotime($_GPC['start_time']),
					"end_time"=>strtotime($_GPC['end_time']),
					"notes"=>$_GPC['notes'],
					"notice"=>$_GPC['notice'],
					"flow"=>$_GPC['flow'],
					"orig_price"=>$_GPC['orig_price'],
					"true_price"=>$_GPC['true_price'],
					"first_level"=>$_GPC['first_level'],
					"second_level"=>$_GPC['second_level'],
					"three_level"=>$_GPC['three_level'],
					"stock"=>$_GPC['stock'],
					"phone"=>$_GPC['phone'],
					"wxcode"=>$_GPC['wxcode'],
					"carousel1"=>$_GPC['carousel1'],
					"carousel2"=>$_GPC['carousel2'],
					"carousel3"=>$_GPC['carousel3'],
					"url1"=>$_GPC['url1'],
					"url2"=>$_GPC['url2'],
					"url3"=>$_GPC['url3'],
				);
				
				if(empty($oneactivity['id'])){
					$dabdata['insert_time']=time();
					$res=pdo_insert("ly_fxhd_activity",$dabdata);
				}else{
					$res=pdo_update("ly_fxhd_activity",$dabdata,array('id'=>$oneactivity['id']));
				}
				if(!empty($res)){
					message("操作成功！",$this->createWebUrl('product',array(),"success"));
				}else{
					message("操作出现问题！","","error");
				}
				
			}
		}
		include $this->template("activity");
	}

	public function doWebCategory(){
		global $_W,$_GPC;
		$thisid=$_GPC['cid'];
		//判断删除
		if($_GPC['shanchu']==1&&!empty($_GPC['cid'])){
			$result = pdo_delete('ly_fxhd_category', array('id' =>$_GPC['cid']));
			if(!empty($result)){
				message("删除成功！",$this->createWebUrl('category'),"success");
			}else{
				message("删除出现问题","","error");
			}
		}
		$thistype;
		//就根据这个分辨类型了。3个状态，0列表，1插入，2更新
		if(empty($thisid)){
			if($_GPC['bainji']==1){
				$thistype=2;
			}else{
				$thistype=0;
			}
		}else{
			if($thisid==-1){
				$thistype=1;
			}elseif($thisid>=0){
				$thistype=2;
			}
		}
		if($thistype==2){
			$onec=pdo_get("ly_fxhd_category",array("id"=>$_GPC['cid']));
			if(empty($onec)){
				message("无此数据！","","error");
			}else{

			}
		}
		if($thistype==0){
			$category=pdo_getall("ly_fxhd_category",array("uniacid"=>$_W['uniacid']));
		}
		if($_W['ispost']){

			if($thistype==1){
				$dbdata=[
					"name"=>$_GPC['name'],
					"uniacid"=>$_W['uniacid']
				];
				$res=pdo_insert("ly_fxhd_category",$dbdata);
				if(!empty($res)){
					message("插入成功",$this->createWebUrl('category'),"success");
				}else{
					message("插入出错","","error");
				}
			}elseif ($thistype==2) {
				$dbdata=[
					"name"=>$_GPC['name'],
					"uniacid"=>$_W['uniacid']
				];
				$res=pdo_update("ly_fxhd_category",$dbdata,['id'=>$thisid]);
				if(!empty($res)){
					message("更新成功",$this->createWebUrl('category'),"success");
				}else{
					message("更新出错","","error");
				}
			}
		}
		include $this->template("category");
	}
	//参与人数
	public function doWebOrders(){
		global $_W,$_GPC;
		$arid=$_GPC['arid'];
		if(!empty($arid)){
			// $usersql="SELECT * FROM  ims_ly_fxhd_superior as s LEFT JOIN ims_ly_fxhd_users as u ON s.userid=u.id WHERE s.artid=".$thisid." AND u.is_robot=0";
			// $userlist=pdo_fetchall($usersql);
			
			echo $this->generate_password();
			exit();
			$userlist=pdo_getall("ly_fxhd_users");

		}
		include $this->template("orders");
	}
	private function generate_password(){ 
		$fpath="../addons/ly_fenxiaohuodong/template/name.txt";
		$str = file_get_contents($fpath);
		$password = mb_substr($str,0,4,"utf-8");
		return $password; 
	} 



	//添加机器人
	public function doWebRobot(){
		global $_W,$_GPC;
		//获取当前活动id
		$thisid=$_GPC['arid'];
		if(empty($thisid)){
			message("没有对应的活动！","","error");
		}else{
			
		}
		//判断删除
		if($_GPC['shanchu']==1&&!empty($_GPC['uid'])){
			$result = pdo_delete('ly_fxhd_users', array('id' =>$_GPC['uid']));
			$result2= pdo_delete('ly_fxhd_superior', array('artid'=>$thisid,'userid'=>$_GPC['uid']));
			if(!empty($result)){
				message("删除成功！",$this->createWebUrl('robot',array("arid"=>$thisid)),"success");
			}else{
				message("删除出现问题",$this->createWebUrl('robot',array("arid"=>$thisid)),"error");
			}
		}
		$thistype;
		//就根据这个分辨类型了。3个状态，0列表，1插入，2更新
		if(empty($thisid)){
			if($_GPC['bainji']==1){
				$thistype=2;
			}else{
				$thistype=0;
			}
		}else{
			if($thisid==-1){
				$thistype=1;
			}elseif($thisid>=0){
				$thistype=2;
			}
		}
		if($thistype==2){
			//查询出类型
			$activity=pdo_getall("ly_fxhd_category",array("uniacid"=>$_W['uniacid']));
			//把这个处理为一个数组key为id，val为name
			$activityarr=[];
			foreach ($activity as $k => $v) {
				$activityarr[$v['id']]=$v['name'];
			}
			$onec=pdo_get("ly_fxhd_goods",array("id"=>$_GPC['gid']));
			if(empty($onec)){
				message("无此数据！","","error");
			}else{

			}
		}
		if($thistype==1){
			//查询出类型
			$activity=pdo_getall("ly_fxhd_category",array("uniacid"=>$_W['uniacid']));
			//把这个处理为一个数组key为id，val为name
			$activityarr=[];
			foreach ($activity as $k => $v) {$activityarr[$v['id']]=$v['name'];}
		}
		if($thistype==0){
			$rebotsql="SELECT * FROM  ims_ly_fxhd_superior as s LEFT JOIN ims_ly_fxhd_users as u ON s.userid=u.id WHERE s.artid=".$thisid." AND u.is_robot=1";
			$rebots=pdo_fetchall($rebotsql);
		}
		if($_W['ispost']){

			if($thistype==1){
				$dbdata=[
					"name"=>$_GPC['name'],
					"shopid"=>$sid,
					"category"=>$_GPC['category'],
					"banner"=>$_GPC['banner'],
					"insert_time"=>time(),
					"details"=>$_GPC['details'],
					"price"=>$_GPC['price'],
					"uniacid"=>$_W['uniacid']
				];
				$res=pdo_insert("ly_fxhd_goods",$dbdata);
				if(!empty($res)){
					message("插入成功",$this->createWebUrl('goods',array("sid"=>$sid)),"success");
				}else{
					message("插入出错","","error");
				}
			}elseif ($thistype==2) {
				$dbdata=[
					"name"=>$_GPC['name'],
					"shopid"=>$sid,
					"category"=>$_GPC['category'],
					"banner"=>$_GPC['banner'],
					"insert_time"=>time(),
					"details"=>$_GPC['details'],
					"price"=>$_GPC['price'],
					"uniacid"=>$_W['uniacid']
				];
				$res=pdo_update("ly_fxhd_goods",$dbdata,['id'=>$thisid]);
				if(!empty($res)){
					message("更新成功",$this->createWebUrl('goods',array("sid"=>$sid)),"success");
				}else{
					message("更新出错",$this->createWebUrl('goods',array("sid"=>$sid)),"error");
				}
			}
		}
		include $this->template("goods");		
	}
	public function doWebGoods(){
		global $_W,$_GPC;
		//h获取当前的店铺id、
		$sid=$_GPC['sid'];
		if(empty($sid)){
			message("没有店铺标识","","error");
		}else{
			$shopname=pdo_get("ly_fxhd_shops",array("id"=>$sid))['name'];
		}
		$thisid=$_GPC['gid'];
		//判断删除
		if($_GPC['shanchu']==1&&!empty($_GPC['gid'])){
			$result = pdo_delete('ly_fxhd_goods', array('id' =>$_GPC['gid']));
			if(!empty($result)){
				message("删除成功！",$this->createWebUrl('goods',array("sid"=>$sid)),"success");
			}else{
				message("删除出现问题",$this->createWebUrl('goods',array("sid"=>$sid)),"error");
			}
		}
		$thistype;
		//就根据这个分辨类型了。3个状态，0列表，1插入，2更新
		if(empty($thisid)){
			if($_GPC['bainji']==1){
				$thistype=2;
			}else{
				$thistype=0;
			}
		}else{
			if($thisid==-1){
				$thistype=1;
			}elseif($thisid>=0){
				$thistype=2;
			}
		}
		if($thistype==2){
			//查询出类型
			$activity=pdo_getall("ly_fxhd_category",array("uniacid"=>$_W['uniacid']));
			//把这个处理为一个数组key为id，val为name
			$activityarr=[];
			foreach ($activity as $k => $v) {
				$activityarr[$v['id']]=$v['name'];
			}
			$onec=pdo_get("ly_fxhd_goods",array("id"=>$_GPC['gid']));
			if(empty($onec)){
				message("无此数据！","","error");
			}else{

			}
		}
		if($thistype==1){
			//查询出类型
			$activity=pdo_getall("ly_fxhd_category",array("uniacid"=>$_W['uniacid']));
			//把这个处理为一个数组key为id，val为name
			$activityarr=[];
			foreach ($activity as $k => $v) {$activityarr[$v['id']]=$v['name'];}
		}
		if($thistype==0){
			$goods=pdo_getall("ly_fxhd_goods",array("uniacid"=>$_W['uniacid'],"shopid"=>$sid));
		}
		if($_W['ispost']){

			if($thistype==1){
				$dbdata=[
					"name"=>$_GPC['name'],
					"shopid"=>$sid,
					"category"=>$_GPC['category'],
					"banner"=>$_GPC['banner'],
					"insert_time"=>time(),
					"details"=>$_GPC['details'],
					"price"=>$_GPC['price'],
					"uniacid"=>$_W['uniacid']
				];
				$res=pdo_insert("ly_fxhd_goods",$dbdata);
				if(!empty($res)){
					message("插入成功",$this->createWebUrl('goods',array("sid"=>$sid)),"success");
				}else{
					message("插入出错","","error");
				}
			}elseif ($thistype==2) {
				$dbdata=[
					"name"=>$_GPC['name'],
					"shopid"=>$sid,
					"category"=>$_GPC['category'],
					"banner"=>$_GPC['banner'],
					"insert_time"=>time(),
					"details"=>$_GPC['details'],
					"price"=>$_GPC['price'],
					"uniacid"=>$_W['uniacid']
				];
				$res=pdo_update("ly_fxhd_goods",$dbdata,['id'=>$thisid]);
				if(!empty($res)){
					message("更新成功",$this->createWebUrl('goods',array("sid"=>$sid)),"success");
				}else{
					message("更新出错",$this->createWebUrl('goods',array("sid"=>$sid)),"error");
				}
			}
		}
		include $this->template("goods");
	}
	//活动列表
	public function doMobileArtlist(){
		global $_W,$_GPC;
		//获取有用的活动
		$sql='SELECT g.id as gid,g.name,g.banner,g.price,a.stock,a.start_time,a.end_time,a.already_peoples,a.participants
		FROM  ims_ly_fxhd_goods as g LEFT JOIN  ims_ly_fxhd_activity as a 
		ON g.id=a.goodid WHERE g.uniacid='.$_W['uniacid'];
		$arts=pdo_fetchall($sql);
		var_dump($arts);
		// include $this->template("artlist");
	}
	//绑定客户信息
	public function doMobileUserinfo(){
		global $_W,$_GPC;
		if($_W['ispost']){
			$res=pdo_update("ly_fxhd_users",
				array(
					"uniacid"=>$_W['uniacid'],
					"phone"=>$_GPC['phone'],
					"address"=>$_GPC['address'],
					"insert_time"=>time()
				),
				array(
					"openid"=>$_W['openid']
				)	
			);
			if(!empty($res)){
				//成功
			}else{
				//失败
			}
		}
	}
	//查看奖金
	public function doMobileMoney_award(){


	}
	//支付
	private function threepay($user,$price,$ticket,$arr){
		//找到此人的订单，然后更新order表
		$order=pdo_get("ly_fxhd_orders",array("ticket"=>$ticket));
		if(empty($order)){
			//如果没有，返回false
			return false;
		}else{
			//有的话，给他更新
			$resup=pdo_update("ly_fxhd_orders",array("id"=>$order['id']),array("type"=>1,"pay_time"=>time()));
			//然后就需要计算返利，查询uperior对照表
			$papa1=$this->doMobileRebate($order['activityid'],$order['userid'],1);
			if($papa1>0){
				$papa2=$this->doMobileRebate($order['activityid'],$papa1,2);
				if($papa2>0){
					$papa3=$this->doMobileRebate($order['activityid'],$papa2,3);
				}
			}
		}
	}
	//返利
	private function doMobileRebate($artid,$userid,$level){
		$oneuser=pdo_get("ly_fxhd_users",array("id"=>$userid));
		//查出这个人的上级
		$papa=pdo_get("ly_fxhd_superior",array("artid"=>$artid,"userid"=>$userid))['id'];
		//查出活动
		$activity=pdo_get("ly_fxhd_activity",array("id"=>$artid));
		// true_price
		$bili=1;
		if($level==1){
			//这是一级
			$bili=$activity['first_level'];
		}elseif ($level==2) {
			$bili=$activity['second_level'];
		}elseif ($level==3) {
			$bili=$activity['three_level'];
		}
		if(!empty($papa)){
			//取出这个上级的记录
			$onepapa=pdo_get("ly_fxhd_users",array("id"=>$papa));
			//计算奖励
			$bonus=$onepapa['bonus']+$activity['true_price']*$bili;
			$savepapa=pdo_update("ly_fxhd_users",array("bonus"=>$bonus),array("id"=>$papa));
			return $papa;
		}else{
			return -1;
		}
		
	}
	public function doMobileArt(){
		global $_W,$_GPC;

		//本人信息
		$user = pdo_get('ly_fxhd_users',array('uniacid'=>$_W['uniacid'],'openid'=>$_W['openid']));
		$account_api = WeAccount::create();
		$user_info =  $account_api->fansQueryInfo($user['openid']);

		//是否是上级分享
		if(!empty($_GPC['parentid'])){
			$parent_openid = pdo_get('ly_fxhd_users',array('uniacid'=>$_W['uniacid'],'id'=>$_GPC['parentid']))['openid'];
			$parent_info = $account_api->fansQueryInfo($parent_openid);
		}
		
		//根据传的活动值，确定这个活动
		$artid=$_GPC['artid'];
		$sql='SELECT g.id as gid,g.name,a.wxcode,a.phone,g.banner,a.invite,a.title,a.true_price,a.orig_price,a.stock,a.start_time,a.end_time,a.already_peoples,a.participants,a.id,a.carousel1,
			a.carousel2,a.carousel3,a.url1,a.url2,a.url3,a.flow,a.notes,a.notice,introduce FROM  ims_ly_fxhd_goods as g LEFT JOIN  ims_ly_fxhd_activity as a 
			ON g.id=a.goodid WHERE g.uniacid='.$_W['uniacid'].' AND a.id ='.$artid;
		$goods=pdo_fetch($sql);

		//购买订单列表
		$sql = 'SELECT u.phone,o.price,o.pay_time,u.openid FROM ims_ly_fxhd_orders AS o LEFT JOIN ims_ly_fxhd_users AS u ON o.userid = u.id WHERE o.type = 1 AND o.activityid = '.$artid.' ORDER BY o.id DESC';
		$orderlist = pdo_fetchall($sql);
		foreach($orderlist as $index => $row){
			$orderlist[$index]['img'] = $account_api->fansQueryInfo($row['openid'])['headimgurl'];
			$orderlist[$index]['nickname'] = $account_api->fansQueryInfo($row['openid'])['nickname'];
		}
		//本人支付订单列表
		$sql1 = 'SELECT u.phone,o.price,o.type,o.pay_time,u.openid FROM ims_ly_fxhd_orders AS o LEFT JOIN ims_ly_fxhd_users AS u ON o.userid = u.id WHERE o.type = 1 AND o.activityid = '.$artid.' AND o.userid = '.$user['id'].' ORDER BY o.id DESC';
		$my_orderlist = pdo_fetchall($sql1);
		//返利明细
		$sql2 = 'SELECT * FROM ims_ly_fxhd_rebate AS r LEFT JOIN ims_ly_fxhd_users AS u ON r.sub_id = u.id WHERE r.user_id = '.$user['id'].' AND r.uniacid = '.$_W['uniacid'];
		$rebate_list = pdo_fetchall($sql2);
	
		if(checksubmit('submit1')){
			var_dump($_GPC);exit;
		}

		include $this->template("art");
	}
	//我的奖金
	public function doMobileBonus(){
		global $_W,$_GPC;
		$user = pdo_get('ly_fxhd_users',array('uniacid'=>$_W['uniacid'],'openid'=>$_W['openid']));
		//返利明细
		$sql = 'SELECT u.name,r.fee,r.insert_time FROM ims_ly_fxhd_rebate AS r LEFT JOIN ims_ly_fxhd_users AS u ON r.sub_id = u.id WHERE r.user_id = '.$user['id'].' AND r.uniacid = '.$_W['uniacid'];
		$rebate_list = pdo_fetchall($sql);
		include $this->template('bonus');
	}
	//订单菜单
	public function doMobileOrder(){
		global $_W,$_GPC;
		$user = pdo_get('ly_fxhd_users',array('uniacid'=>$_W['uniacid'],'openid'=>$_W['openid']));
		$sql = 'SELECT * FROM ims_ly_fxhd_orders AS o LEFT JOIN ims_ly_fxhd_users AS u ON o.userid = u.id LEFT JOIN ims_ly_fxhd_goods AS g ON o.goodid = g.id WHERE o.userid = '.$user['id'].' AND o.type =1 AND o.uniacid = '.$_W['uniacid'];
		$order_list = pdo_fetchall($sql);
		include $this->template('order');
	}
	//主页
	public function doMobileMain(){
		global $_W,$_GPC;
		$sql = 'SELECT a.id as aid,a.end_time,g.banner,a.title FROM ims_ly_fxhd_activity AS a LEFT JOIN ims_ly_fxhd_goods AS g ON a.goodid = g.id WHERE a.end_time >'.time().' ORDER BY a.id DESC';
		$act_list = pdo_fetchall($sql);
		include $this->template('main');
	}
	//注册信息

	public function doMobileRegister(){
		global $_W,$_GPC;
		$user = pdo_get('ly_fxhd_users',array('uniacid'=>$_W['uniacid'],'openid'=>$_W['openid']));
		if($_W['isajax']){
			$data = array(
				'name'=>$_GPC['name'],
				'phone'=>$_GPC['phone']
			);
			$update_info = pdo_update('ly_fxhd_users',$data,array('uniacid'=>$_W['uniacid'],'id'=>$user['id']));
			if(!empty($update_info)){
				$resArr['code'] =0;
			}else{
				$resArr['code'] =-1;
			}
			echo json_encode($resArr);exit;
		}
		//根据传的活动值，确定这个活动
		$artid=$_GPC['artid'];
		$sql='SELECT a.title,a.true_price,a.notice,a.id as id FROM  ims_ly_fxhd_goods as g LEFT JOIN  ims_ly_fxhd_activity as a ON g.id=a.goodid WHERE g.uniacid='.$_W['uniacid'].' AND a.id ='.$artid;
		$goods=pdo_fetch($sql);
		include $this->template('register');
	}
	private function create_orders($arr,$Robot){
		if($Robot==0){
			$savearr=array(
				"uniacid"=>$arr['uniacid'],
				"userid"=>$arr['userid'],
				"activityid"=>$arr['activityid'],
				"insert_time"=>time(),
				"type"=>0,
				"price"=>$arr['price'],
			);
			$saveorder=pdo_insert("ly_fxhd_orders",$savearr);	
		}
	}
}