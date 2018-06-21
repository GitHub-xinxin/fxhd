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

		//根据传的活动值，确定这个活动
		$script_name=strpos($_W['script_name'],'app');
		//监测当前是手机端web端后台，web端不检查。
		if(!$script_name){
			return true;
		}
		$artid = $_GPC['artid'];
		$goods = pdo_get('ly_fxhd_activity',array('uniacid'=>$_W['uniacid'],'id'=>$artid));

		if(!empty($artid)){
			/**
			 * 判断活动是否结束
			 */
			if($goods['end_time'] < time())
			message('该活动已经结束','','error');
			/**
			 * 活动是否删除
			 */
			if($goods['deleted'] == 1)
				message('该活动已取消','','error');
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
			//不能是自己
			if($user_id != $parentid){
				//parentid不能为空
				if(!empty($parentid)){
					//是否已经存在上级
					$is_has = pdo_get('ly_fxhd_superior',array('artid'=>$_GPC['artid'],'userid'=>$user_id));
					//如果不存在

					if(empty($is_has)){
						$flag = 0;
						$father_id = $parentid;
						while(!empty($father_id)){	
							$father_id = pdo_get("ly_fxhd_superior",array('artid'=>$_GPC['artid'],'userid'=>$father_id))['fatherid'];
							if($user_id == $father_id){
								$flag = 1;
								break;
							}		
						};
						if($flag == 0)
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

		$menu_info = $this->menu($_GPC['artid']);
		$menu_count = count($menu_info);

		$cr = pdo_get('ly_fxhd_copyright',array('uniacid'=>$_W['uniacid']));
		if(checksubmit()){
			//活动信息
			$act_info =pdo_get('ly_fxhd_activity',array('uniacid'=>$_W['uniacid'],'id'=>$_GPC['artid']));
			if($act_info['mode'] == 0)   //仅自提
				$mode = 0;
			else if($act_info['mode'] == 1)//仅快递
				$mode = 1;
			else if($act_info['mode'] == 2)//快递或自提
				$mode = $_GPC['mode'];

			if($act_info['mode'] == 0){ //仅自提
				$data =array(
					'name'=>$_GPC['name'],
					'phone'=>$_GPC['phone']
				);
			}else{
				$data =array(    //自提货快递   
					'name'=>$_GPC['name'],
					'phone'=>$_GPC['phone'],
					'province'=>$_GPC['addres']['province'],
					'city'=>$_GPC['addres']['city'],
					'district'=>$_GPC['addres']['district'],
					'address'=>$_GPC['address']
				);
			}
			pdo_update('ly_fxhd_users',$data,array('uniacid'=>$_W['uniacid'],'openid'=>$_W['openid']));//更新用户表
		
			$user = pdo_get('ly_fxhd_users',array('openid'=>$_W['openid'],'uniacid'=>$_W['uniacid']));//获取用户信息
	
			/**
			 *  订单参数
			 */
			$pay_agr = array(
				'uniacid'=>$_W['uniacid'],
				'userid'=>$user['id'],
				'activityid'=>$_GPC['artid'],
				'type'=>0,
				'goodid'=>$act_info['goodid'],
				'insert_time'=>time(),
				'mode'=>$mode,//发货方式
				'price'=>$act_info['true_price'],
				'ticket'=>'order_'.date('YmdHis').random(10, 1)//随机订单号
			);
			if($mode != 0)
				$pay_agr['address'] = $user['province'].$user['city'].$user['district'].$user['address'];  //发货地址  避免更新i了地址 已经下单的发货地址也要变
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
		load()->func('tpl');
		//根据传的活动值，确定这个活动
		$artid=$_GPC['artid'];
		$sql='SELECT a.title,a.true_price,a.notice,a.mode,a.id as aid FROM  ims_ly_fxhd_goods as g LEFT JOIN  ims_ly_fxhd_activity as a ON g.id=a.goodid WHERE g.uniacid='.$_W['uniacid'].' AND a.id ='.$artid;
		$goods=pdo_fetch($sql);
		/**
		 * 用户信息
		 */
		$user = pdo_get('ly_fxhd_users',array('openid'=>$_W['openid'],'uniacid'=>$_W['uniacid']));
		include $this->template('register');
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
			//获取订单id 需存入返利表
			$orderid = pdo_get('ly_fxhd_orders',array('uniacid'=>$_W['uniacid'],'ticket'=>$params['tid']))['id'];
			if(empty($modif_order_status)){
				logging_run('更新订单支付状态失败,ticket=='.$params['tid'],'info','fxhd_status');
			}else{
				//查找购买人
				$order_info = pdo_get('ly_fxhd_orders',array('uniacid'=>$_W['uniacid'],'ticket'=>$params['tid'],'type'=>1));
				/**
				 * 查看该活动是否设置 即时返利
				 */
				$act_auto = pdo_get('ly_fxhd_activity',array('uniacid'=>$_W['uniacid'],'id'=>$order_info['activityid']))['rebate'];
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
							/**
							 * 要随机得到一级返利的随机数
							 */
	
							$rand_money = $this->rand_rebate(1,$art_info['id']);
			
							if($rand_money != -1){
								$one_pay_result = pdo_update('ly_fxhd_users',array('bonus +='=>$rand_money),array('id'=>$one_tier['fatherid'],'uniacid'=>$_W['uniacid']));
								if(empty($one_pay_result)){
									logging_run('更新一级返利失败,ticket=='.$params['tid'],'info','fxhd_rebate');
								}else{
									//录入返利表
									pdo_insert('ly_fxhd_rebate',array('orderid'=>$orderid,'uniacid'=>$_W['uniacid'],'act_id'=>$art_info['id'],'user_id'=>$one_tier['fatherid'],'sub_id'=>$order_info['userid'],'fee'=>$rand_money,'insert_time'=>time()));
									//是否立即返利
									if($act_auto == 0){
										$money = $rand_money;
										if($money >= 1){
											//获取返利人的openid
											
											$openid = pdo_get('ly_fxhd_users',array('uniacid'=>$_W['uniacid'],'id'=>$one_tier['fatherid']))['openid'];
											$status = $this->sendRedPacket($openid,$money * 100);
											if($status === true){
												//录入返利表
												pdo_insert('ly_fxhd_apply_rebate',array('uniacid'=>$_W['uniacid'],'userid'=>$one_tier['fatherid'],'fee'=>$money,'status'=>1,'insert_time'=>time()));
											}else{
												pdo_insert('ly_fxhd_apply_rebate',array('uniacid'=>$_W['uniacid'],'userid'=>$one_tier['fatherid'],'fee'=>$money,'status'=>2,'insert_time'=>time()));
											}
										}
									}
								}		
							}
							//更新二级返利	上级奖金数	
							$two_tier = pdo_get('ly_fxhd_superior',array('artid'=>$order_info['activityid'],'userid'=>$one_tier['fatherid']));
							if(empty($two_tier)){
								logging_run('查找上级的上级失败,ticket=='.$params['tid'],'info','fxhd_rebate');
							}else{
							
								/**
								 * 二级随机奖金数
								 */
								$rand_money2 = $this->rand_rebate(2,$art_info['id']);
								
								if($rand_money2 != -1){
									$two_pay_result = pdo_update('ly_fxhd_users',array('bonus +='=>$rand_money2),array('id'=>$two_tier['fatherid'],'uniacid'=>$_W['uniacid']));
									if(empty($two_pay_result)){
										logging_run('更新二级返利失败,ticket=='.$params['tid'],'info','fxhd_rebate');
									}else{
										//录入返利表
										pdo_insert('ly_fxhd_rebate',array('orderid'=>$orderid,'uniacid'=>$_W['uniacid'],'act_id'=>$art_info['id'],'user_id'=>$two_tier['fatherid'],'sub_id'=>$order_info['userid'],'fee'=>$rand_money2,'insert_time'=>time()));
										//是否立即返利
										if($act_auto == 0){
											$money = $rand_money2;
											if($money >= 1){
												//获取openid
												$openid = pdo_get('ly_fxhd_users',array('uniacid'=>$_W['uniacid'],'id'=>$two_tier['fatherid']))['openid'];
												$status = $this->sendRedPacket($openid,$money*100);
												if($status === true){
													//录入返利表
													pdo_insert('ly_fxhd_apply_rebate',array('uniacid'=>$_W['uniacid'],'userid'=>$two_tier['fatherid'],'fee'=>$money,'status'=>1,'insert_time'=>time()));
												}else{
													pdo_insert('ly_fxhd_apply_rebate',array('uniacid'=>$_W['uniacid'],'userid'=>$two_tier['fatherid'],'fee'=>$money,'status'=>2,'insert_time'=>time()));
												}
											}
										}
									}
								}	
								//更新三级返利  上级金额数
								$three_tier = pdo_get('ly_fxhd_superior',array('artid'=>$order_info['activityid'],'userid'=>$two_tier['fatherid']));
								if(empty($three_tier)){
									logging_run('查找上级的上级的上级失败,ticket=='.$params['tid'],'info','fxhd_rebate');
								}else{
		
									/**
									 * 三级随机奖金
									 */
									$rand_money3 = $this->rand_rebate(3,$art_info['id']);

									if($rand_money3 != -1){
										$three_pay_result = pdo_update('ly_fxhd_users',array('bonus +='=>$rand_money3),array('id'=>$three_tier['fatherid'],'uniacid'=>$_W['uniacid']));
										if(empty($three_pay_result)){
											logging_run('更新三级返利失败,ticket=='.$params['tid'],'info','fxhd_rebate');
										}else{
											//录入返利表
											pdo_insert('ly_fxhd_rebate',array('orderid'=>$orderid,'uniacid'=>$_W['uniacid'],'act_id'=>$art_info['id'],'user_id'=>$three_tier['fatherid'],'sub_id'=>$order_info['userid'],'fee'=>$rand_money3,'insert_time'=>time()));
											//是否立即返利
											if($act_auto == 0){
												$money = $rand_money3;
												if($money >= 1){
													//获取openid
													$openid = pdo_get('ly_fxhd_users',array('uniacid'=>$_W['uniacid'],'id'=>$three_tier['fatherid']))['openid'];
													$status = $this->sendRedPacket($openid,$money*100);
													if($status === true){
														//录入返利表
														pdo_insert('ly_fxhd_apply_rebate',array('uniacid'=>$_W['uniacid'],'userid'=>$three_tier['fatherid'],'fee'=>$money,'status'=>1,'insert_time'=>time()));
													}else{
														pdo_insert('ly_fxhd_apply_rebate',array('uniacid'=>$_W['uniacid'],'userid'=>$three_tier['fatherid'],'fee'=>$money,'status'=>2,'insert_time'=>time()));
													}
												}
											}
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


	public function get_rand($proArr){
		$result = ''; 
		//概率数组的总概率精度 
		$proSum = array_sum($proArr); 
		//概率数组循环 
		foreach ($proArr as $key => $proCur) { 
			$randNum = mt_rand(1, $proSum); 
			if ($randNum <= $proCur) { 
				$result = $key; 
				break; 
			} else { 
				$proSum -= $proCur; 
			} 
		} 
		return $result; 
	}
	//随机数生成
	public function rand_rebate($level,$aid){
		global $_W,$_GPC;

		$act_info = pdo_get('ly_fxhd_activity',array('uniacid'=>$_W['uniacid'],'id'=>$aid));
		$num = rand(1,5);
		$fee = -1;
		$pro_arr =array();
		if($level == 1){
			$pro_arr[0] = $act_info['one_level_one_pro'];
			$pro_arr[1] = $act_info['one_level_two_pro'];
			$pro_arr[2] = $act_info['one_level_three_pro'];
			$pro_arr[3] = $act_info['one_level_four_pro'];
			$pro_arr[4] = $act_info['one_level_five_pro'];
			$num = $this->get_rand($pro_arr);
			switch($num){
				case 0: $fee = $act_info['one_level_one'];break;
				case 1: $fee = $act_info['one_level_two'];break;
				case 2: $fee = $act_info['one_level_three'];break;
				case 3: $fee = $act_info['one_level_four'];break;
				case 4: $fee = $act_info['one_level_five'];break;
			}
		}elseif($level == 2){
			$pro_arr[0] = $act_info['two_level_one_pro'];
			$pro_arr[1] = $act_info['two_level_two_pro'];
			$pro_arr[2] = $act_info['two_level_three_pro'];
			$pro_arr[3] = $act_info['two_level_four_pro'];
			$pro_arr[4] = $act_info['two_level_five_pro'];
			$num = $this->get_rand($pro_arr);
			switch($num){
				case 0: $fee = $act_info['two_level_one'];break;
				case 1: $fee = $act_info['two_level_two'];break;
				case 2: $fee = $act_info['two_level_three'];break;
				case 3: $fee = $act_info['two_level_four'];break;
				case 4: $fee = $act_info['two_level_five'];break;
			}
		}elseif($level == 3){
			$pro_arr[0] = $act_info['three_level_one_pro'];
			$pro_arr[1] = $act_info['three_level_two_pro'];
			$pro_arr[2] = $act_info['three_level_three_pro'];
			$pro_arr[3] = $act_info['three_level_four_pro'];
			$pro_arr[4] = $act_info['three_level_five_pro'];
			$num = $this->get_rand($pro_arr);
			switch($num){
				case 0: $fee = $act_info['three_level_one'];break;
				case 1: $fee = $act_info['three_level_two'];break;
				case 2: $fee = $act_info['three_level_three'];break;
				case 3: $fee = $act_info['three_level_four'];break;
				case 4: $fee = $act_info['three_level_five'];break;
			}
		}
		return $fee;
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
					"introduce"=>$_GPC['introduce'],
					"openid"=>$_GPC['openid']
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
					"introduce"=>$_GPC['introduce'],
					"openid"=>$_GPC['openid']
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
		$infos=pdo_getall("ly_fxhd_infos",['uniacid'=>$_W['uniacid'],'type'=>-1]);
		if($_GPC['shanchu'] == 1){
			
			if(pdo_delete('ly_fxhd_infos',array('uniacid'=>$_W['uniacid'],'id'=>$_GPC['sid']))){
				message('删除成功',$this->createWebUrl('info'),'success');
			}else{
				message('删除失败',$this->createWebUrl('info'),'error');
			}
		}
		include $this->template("info");

	}
	
	public function doWebProduct(){
		//产品
		global $_W,$_GPC;
		$thisid=$_GPC['gid'];
		//判断删除
		if($_GPC['shanchu']==1&&!empty($_GPC['gid'])){
			$result = pdo_update('ly_fxhd_goods',array('deleted'=>1),array('id' =>$_GPC['gid']));
			//活动标记为删除
			pdo_update('ly_fxhd_activity',array('deleted'=>1),array('uniacid'=>$_W['uniacid'],'goodid'=>$_GPC['gid']));
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
			$sqla='SELECT a.id as aid,g.id as gid,g.name,g.banner,a.true_price,a.stock,a.start_time,a.end_time,a.already_peoples,a.qrcode,a.participants
			FROM  ims_ly_fxhd_goods as g LEFT JOIN  ims_ly_fxhd_activity as a ON g.id=a.goodid WHERE g.deleted =0 and a.deleted = 0 and g.uniacid='.$_W['uniacid'];
			$goods=pdo_fetchall($sqla);
			foreach($goods as $index=>$row){
				if(!empty($row['aid'])){
					$goods[$index]['join_count'] = count(pdo_fetchall('SELECT * FROM '.tablename('ly_fxhd_superior').' WHERE artid = '.$row['aid']));
					$goods[$index]['buy_count'] = count(pdo_getall('ly_fxhd_orders',array('uniacid'=>$_W['uniacid'],'type'=>1,'activityid'=>$row['aid'])));
				}
			}
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
					"sketch"=>$_GPC['sketch'],
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
					"rebate"=>$_GPC['rebate'],
					"red_count"=>$_GPC['red_count'],
					"red_min"=>$_GPC['red_min'],
					"red_max"=>$_GPC['red_max'],
					"red_kind"=>$_GPC['red_kind'],
					"tech_support"=>$_GPC['tech_support'],
					"tech_url"=>$_GPC['tech_url'],
					"phone_pic"=>$_GPC['phone_pic'],
					"kefu_pic"=>$_GPC['kefu_pic'],
					"notice_pic"=>$_GPC['notice_pic'],
					"robot"=>$_GPC['robot'],
					"careful"=>$_GPC['careful'],
					"mode"=>$_GPC['mode'],
					"one_level_one"=>$_GPC['one_level_one'],
					"one_level_two"=>$_GPC['one_level_two'],
					"one_level_three"=>$_GPC['one_level_three'],
					"one_level_four"=>$_GPC['one_level_four'],
					"one_level_five"=>$_GPC['one_level_five'],
					"two_level_one"=>$_GPC['two_level_one'],
					"two_level_two"=>$_GPC['two_level_two'],
					"two_level_three"=>$_GPC['two_level_three'],
					"two_level_four"=>$_GPC['two_level_four'],
					"two_level_five"=>$_GPC['two_level_five'],
					"three_level_one"=>$_GPC['three_level_one'],
					"three_level_two"=>$_GPC['three_level_two'],
					"three_level_three"=>$_GPC['three_level_three'],
					"three_level_four"=>$_GPC['three_level_four'],
					"three_level_five"=>$_GPC['three_level_five'],
					"one_level_one_pro"=>$_GPC['one_level_one_pro'],
					"one_level_two_pro"=>$_GPC['one_level_two_pro'],
					"one_level_three_pro"=>$_GPC['one_level_three_pro'],
					"one_level_four_pro"=>$_GPC['one_level_four_pro'],
					"one_level_five_pro"=>$_GPC['one_level_five_pro'],
					"two_level_one_pro"=>$_GPC['two_level_one_pro'],
					"two_level_two_pro"=>$_GPC['two_level_two_pro'],
					"two_level_three_pro"=>$_GPC['two_level_three_pro'],
					"two_level_four_pro"=>$_GPC['two_level_four_pro'],
					"two_level_five_pro"=>$_GPC['two_level_five_pro'],
					"three_level_one_pro"=>$_GPC['three_level_one_pro'],
					"three_level_two_pro"=>$_GPC['three_level_two_pro'],
					"three_level_three_pro"=>$_GPC['three_level_three_pro'],
					"three_level_four_pro"=>$_GPC['three_level_four_pro'],
					"three_level_five_pro"=>$_GPC['three_level_five_pro']
				);
				
				if($_GPC['xf_disabled'] == 0){
					$dabdata["xf_disabled"] = $_GPC['xf_disabled'];
					$dabdata["xf_info"] = $_GPC['xf_info'];
				}else{
					$dabdata["xf_disabled"] = $_GPC['xf_disabled'];
					$dabdata["xf_info"] = '';
				}
				if(empty($oneactivity['id'])){
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
	//首页 轮播图管理
	public function doWebMain_pic(){
		global $_W,$_GPC;

		if(checksubmit()){
			$data =array(
				'carousel1'=>$_GPC['carousel1'],
				'carousel2'=>$_GPC['carousel2'],
				'carousel3'=>$_GPC['carousel3'],
				'url1'=>$_GPC['url1'],
				'url2'=>$_GPC['url2'],
				'url3'=>$_GPC['url3'],
				'uniacid'=>$_W['uniacid']		
			);
			if(empty($_GPC['id'])){
				$res = pdo_insert('ly_fxhd_main_pic',$data);
			}else{
				$res = pdo_update('ly_fxhd_main_pic',$data,array('uniacid'=>$_W['uniacid'],'id'=>$_GPC['id']));
			}
			if($res)
				message('操作成功',$this->createWebUrl('main_pic'),'success');
			else
				message('操作失败',$this->createWebUrl('main_pic'),'error');
		}
		$main_pic = pdo_get('ly_fxhd_main_pic',array('uniacid'=>$_W['uniacid']));
		include $this->template('main_pic');
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
					"uniacid"=>$_W['uniacid'],
					"pic"=>$_GPC['pic']
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
					"uniacid"=>$_W['uniacid'],
					"pic"=>$_GPC['pic']
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
			$usersql="SELECT * FROM  ims_ly_fxhd_superior as s LEFT JOIN ims_ly_fxhd_users as u ON s.userid=u.id WHERE s.artid=".$arid." AND u.is_robot=0";
			$userlist=pdo_fetchall($usersql);
			
			// echo $this->generate_password();
		
			// $userlist=pdo_getall("ly_fxhd_users");

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
	/**
	 * 判断菜单个数
	 */
	public function menu($actid){
		global $_W,$_GPC;

		$info = pdo_get('ly_fxhd_activity',array('uniacid'=>$_W['uniacid'],'id'=>$actid))['bottom_hrefs'];
		return unserialize($info);
	}
	public function doMobileArt(){
		global $_W,$_GPC;

		$menu_info = $this->menu($_GPC['artid']);
		$menu_count = count($menu_info);
		$crr = pdo_get('ly_fxhd_copyright',array('uniacid'=>$_W['uniacid']));
		//根据传的活动值，确定这个活动
		$artid=$_GPC['artid'];
		$sql='SELECT g.id as gid,g.name,g.banner,a.* FROM  ims_ly_fxhd_goods as g LEFT JOIN  ims_ly_fxhd_activity as a 
			ON g.id=a.goodid WHERE g.uniacid='.$_W['uniacid'].' AND a.id ='.$artid;
		$goods=pdo_fetch($sql);
		
		//本人信息
		$user = pdo_get('ly_fxhd_users',array('uniacid'=>$_W['uniacid'],'openid'=>$_W['openid']));
		$account_api = WeAccount::create();
		//获取token
		$token = $account_api->getAccessToken();
		$res_date = ihttp_get('https://api.weixin.qq.com/cgi-bin/ticket/getticket?access_token='.$token.'&type=jsapi');
		$res_date = json_decode($res_date['content'],true);
		$ticket=$res_date['ticket'];
		$url = $_W['siteurl'];
		$str="QWERTYUIOPASDFGHJKLZXCVBNM1234567890qwertyuiopasdfghjklzxcvbnm";
		str_shuffle($str);
		$noncestr=substr(str_shuffle($str),26,10);
		$timestamp=time();
		$string1="jsapi_ticket=".$ticket."&noncestr=".$noncestr."&timestamp=".$timestamp."&url=".$url;
		
		//获取签名
		$signature=sha1($string1);

		if(empty($user['nickname']) && empty($user['avatar'])){
			//未关注公众号 手动获取用户信息接口
			if (empty($_W['fans']['nickname'])) {
				mc_oauth_userinfo();
				//判断是否已经存入
				pdo_update('ly_fxhd_users',array('nickname'=>$_W['fans']['tag']['nickname'],'avatar'=>$_W['fans']['tag']['avatar']),array('uniacid'=>$_W['uniacid'],'openid'=>$_W['openid']));
				$user_info = pdo_get('ly_fxhd_users',array('uniacid'=>$_W['uniacid'],'openid'=>$_W['openid']));
			}else{
					
				$temp =  $account_api->fansQueryInfo($user['openid']);
				if(empty($temp['nickname'])){
					//未关注公众号 但是已经授权了 没有存表
					pdo_update('ly_fxhd_users',array('nickname'=>$_W['fans']['tag']['nickname'],'avatar'=>$_W['fans']['tag']['avatar']),array('uniacid'=>$_W['uniacid'],'openid'=>$_W['openid']));
					$user_info = pdo_get('ly_fxhd_users',array('uniacid'=>$_W['uniacid'],'openid'=>$_W['openid']));
				}else{
						//关注了公众号获取用户信息
					pdo_update('ly_fxhd_users',array('nickname'=>$temp['nickname'],'avatar'=>$temp['headimgurl']),array('uniacid'=>$_W['uniacid'],'openid'=>$_W['openid']));
					$user_info = pdo_get('ly_fxhd_users',array('uniacid'=>$_W['uniacid'],'openid'=>$_W['openid']));
				}
			}
		}else{
			$user_info = $user;
		}
		
		//是否是上级分享
		if(!empty($_GPC['parentid'])){
			//改为从表中取头像与昵称
			$parent_info = pdo_get('ly_fxhd_users',array('uniacid'=>$_W['uniacid'],'id'=>$_GPC['parentid']));
		}
		
		
		//购买订单列表
		$sql = 'SELECT u.phone,o.pay_time,u.nickname,u.avatar FROM ims_ly_fxhd_orders AS o LEFT JOIN ims_ly_fxhd_users AS u ON o.userid = u.id WHERE o.type = 1 AND o.activityid = '.$artid.' ORDER BY o.id DESC';
		$orderlist = pdo_fetchall($sql);
		//判断是否开启机器人
		
		if($goods['robot'] == 1){
			$robot_list = pdo_getall('ly_fxhd_robot_order',array('uniacid'=>$_W['uniacid'],'activityid'=>$artid),array('nickname','avatar','pay_time','phone'));
			$orderlist = array_merge($orderlist,$robot_list);
		}
		include $this->template("art");
	}
	//我的奖金
	public function doMobileBonus(){
		global $_W,$_GPC;
		$cr = pdo_get('ly_fxhd_copyright',array('uniacid'=>$_W['uniacid']));
		if($_W['isajax']){
			/**
			 * 检查是否设置自动返利
			 */
			$resArr['code'] = 0;
			$user_id = pdo_get('ly_fxhd_users',array('uniacid'=>$_W['uniacid'],'openid'=>$_W['openid']))['id'];
			$is_has = pdo_get('ly_fxhd_apply_rebate',array('uniacid'=>$_W['uniacid'],'userid'=>$user_id,'status'=>0));
			/**
			 * 先检查是否有返利在审核中，如果有不能重复申请
			 */
			if($is_has){
				$resArr['code'] = 1;
			}else{	
				/**
				 * 需后台审核返利
				 * 插入返利表等待审核同意
				 */		
				$data = array(
					'uniacid'=>$_W['uniacid'],
					'userid'=>$user_id,
					'status'=>0,
					'fee'=>$_GPC['fee'],
					'insert_time'=>time()
				);
				$warting_apply = pdo_insert('ly_fxhd_apply_rebate',$data);
				if(!$warting_apply){
					$resArr['code'] = 2;
				}			
			}	
			echo json_encode($resArr);exit;
		}
		$menu_info = $this->menu($_GPC['artid']);
		$menu_count = count($menu_info);
		$user = pdo_get('ly_fxhd_users',array('uniacid'=>$_W['uniacid'],'openid'=>$_W['openid']));
		/**
		 * 获取活动注意事项
		 */
		$careful = pdo_get('ly_fxhd_activity',array('uniacid'=>$_W['uniacid'],'id'=>$_GPC['artid']))['careful'];
		//返利明细
		$sql = 'SELECT u.nickname,r.fee,r.insert_time FROM ims_ly_fxhd_rebate AS r LEFT JOIN ims_ly_fxhd_users AS u ON r.sub_id = u.id WHERE r.user_id = '.$user['id'].' AND r.uniacid = '.$_W['uniacid'];
		$rebate_list = pdo_fetchall($sql);
		$sql2 = 'SELECT u.nickname,r.fee,r.insert_time FROM ims_ly_fxhd_send_packet AS r LEFT JOIN ims_ly_fxhd_users AS u ON r.userid = u.id WHERE r.userid = '.$user['id'].' AND r.uniacid = '.$_W['uniacid'];
		$rebate_list = pdo_fetchall($sql);
		$red_list = pdo_fetchall($sql2);
		$total = 0;
		$success_count = 0;
		foreach($rebate_list as $index=>$row){
			$total += $row['fee'];
		}
		//总的收益 - 已经成功提现的金额数 = 奖金数
		$rebate_success = pdo_getall('ly_fxhd_apply_rebate',array('uniacid'=>$_W['uniacid'],'userid'=>$user['id'],'status'=>1));
		foreach($rebate_success as $index=>$row){
			$success_count += $row['fee'];
		}
		$total -= $success_count;
		if($total <0)
			$total = 0;
		//是否等待提现申请
		$is_has = pdo_get('ly_fxhd_apply_rebate',array('uniacid'=>$_W['uniacid'],'userid'=>$user['id'],'status'=>array(0,2)));
		include $this->template('bonus');
	}
	//订单菜单
	public function doMobileOrder(){
		global $_W,$_GPC;
		$menu_info = $this->menu($_GPC['artid']);
		$menu_count = count($menu_info);
		$cr = pdo_get('ly_fxhd_copyright',array('uniacid'=>$_W['uniacid']));
		$user = pdo_get('ly_fxhd_users',array('uniacid'=>$_W['uniacid'],'openid'=>$_W['openid']));
		$sql = 'SELECT * FROM ims_ly_fxhd_orders AS o LEFT JOIN ims_ly_fxhd_users AS u ON o.userid = u.id LEFT JOIN ims_ly_fxhd_goods AS g ON o.goodid = g.id WHERE o.userid = '.$user['id'].' AND o.type =1 AND o.uniacid = '.$_W['uniacid'].' ORDER BY o.id DESC';
		$order_list = pdo_fetchall($sql);
		include $this->template('order');
	}
	//主页
	public function doMobileMain(){
		global $_W,$_GPC;

		$menu_info = $this->menu($_GPC['artid']);
		$menu_count = count($menu_info);
		$cr = pdo_get('ly_fxhd_copyright',array('uniacid'=>$_W['uniacid']));
		//轮播图
		$pic = pdo_get('ly_fxhd_main_pic',array('uniacid'=>$_W['uniacid']));
		//种类列表
		$kind_list = pdo_getall('ly_fxhd_category',array('uniacid'=>$_W['uniacid']),array(),'','id desc','limit ');
		//默认开始显示全部种类
		if(empty($_GPC['kind']) || $_GPC['kind'] == -1){
			$sql = 'SELECT a.id as aid,a.end_time,a.start_time,g.banner,a.title,a.orig_price,a.true_price,a.sketch FROM ims_ly_fxhd_activity AS a LEFT JOIN ims_ly_fxhd_goods AS g ON a.goodid = g.id WHERE a.end_time >'.time().' AND a.deleted = 0 ORDER BY a.id DESC';
			$act_list = pdo_fetchall($sql);

			foreach($act_list as $index=>$row){
				$act_list[$index]['head_img'] = array();
				$users = pdo_getall('ly_fxhd_orders',array('uniacid'=>$_W['uniacid'],'activityid'=>$row['aid'],'type'=>1));
				foreach($users as $x=>$val){
					array_push($act_list[$index]['head_img'],pdo_get('ly_fxhd_users',array('uniacid'=>$_W['uniacid'],'id'=>$val['userid']))['avatar']);
				}
				$act_list[$index]['join_count'] = count(pdo_getall('ly_fxhd_superior',array('artid'=>$row['aid'])));
			}
		}else{
			//根据种类选择活动
			$sql = 'SELECT a.id as aid,a.end_time,a.start_time,g.banner,a.title,a.orig_price,a.true_price,a.sketch FROM ims_ly_fxhd_activity AS a LEFT JOIN ims_ly_fxhd_goods AS g ON a.goodid = g.id WHERE g.category = '.$_GPC['kind'].' AND a.end_time >'.time().' AND a.deleted = 0 ORDER BY a.id DESC';
			$act_list = pdo_fetchall($sql);

			foreach($act_list as $index=>$row){
				$act_list[$index]['head_img'] = array();
				$users = pdo_getall('ly_fxhd_orders',array('uniacid'=>$_W['uniacid'],'activityid'=>$row['aid'],'type'=>1));
				foreach($users as $x=>$val){
					array_push($act_list[$index]['head_img'],pdo_get('ly_fxhd_users',array('uniacid'=>$_W['uniacid'],'id'=>$val['userid']))['avatar']);
				}
				$act_list[$index]['join_count'] = count(pdo_getall('ly_fxhd_superior',array('artid'=>$row['aid'])));
			}
		}
		
		include $this->template('main');
	}
	//版权管理
	public function doWebCopyright_mag(){
		global $_W,$_GPC;

		if(checksubmit()){
			$data =array(
				'uniacid'=>$_W['uniacid'],
				'name'=>$_GPC['name'],
				'url'=>$_GPC['url']
			);

			if(empty($_GPC['id']))
				$res = pdo_insert('ly_fxhd_copyright',$data);
			else
				$res = pdo_update('ly_fxhd_copyright',$data,array('uniacid'=>$_W['uniacid'],'id'=>$_GPC['id']));
			if($res)
				message('操作成功',$this->createWebUrl('copyright_mag'),'success');
			else
				message('操作失败',$this->createWebUrl('copyright_mag'),'error');
		}
		$copyr = pdo_get('ly_fxhd_copyright',array('uniacid'=>$_W['uniacid']));
		include $this->template('copyright_mag');
	}
	/**
	 * 编辑底部菜单
	 */
	public function doWebEdit_menu(){
		global $_W,$_GPC;
		
		if(empty($_GPC['aid'])){
			message('请先创建活动',$this->createWebUrl('product'),'info');
		}
		if($_W['ispost']){
			/**
			 * 序列化菜单
			 */
			$menu =array();
			$temp1 =array();
			$temp2 =array();
			$temp3 =array();
			$temp4 =array();
			$temp5 =array();
			$temp6 =array();
			if($_GPC['menu_display_one'] == 0){
				$temp1['menu_name']=$_GPC['menu_name_one'];
				$temp1['menu_link']=$_GPC['menu_link_one'];
				$temp1['menu_pic']=$_GPC['menu_pic_one'];
				$temp1['menu_display'] =1;
				array_push($menu,$temp1);
			}
			if($_GPC['menu_display_two'] == 0){
				$temp2['menu_name']=$_GPC['menu_name_two'];
				$temp2['menu_link']=$_GPC['menu_link_two'];
				$temp2['menu_pic']=$_GPC['menu_pic_two'];
				$temp2['menu_display'] =1;
				array_push($menu,$temp2);
			}
			if($_GPC['menu_display_three'] == 0){
				$temp3['menu_name']=$_GPC['menu_name_three'];
				$temp3['menu_link']=$_GPC['menu_link_three'];
				$temp3['menu_pic']=$_GPC['menu_pic_three'];
				$temp3['menu_display'] =1;
				array_push($menu,$temp3);
			}
			if($_GPC['menu_display_four'] == 0){
				$temp4['menu_name']=$_GPC['menu_name_four'];
				$temp4['menu_link']=$_GPC['menu_link_four'];
				$temp4['menu_pic']=$_GPC['menu_pic_four'];
				$temp4['menu_display'] =1;
				array_push($menu,$temp4);
			}
			if($_GPC['menu_display_five'] == 0){
				$temp5['menu_name']=$_GPC['menu_name_five'];
				$temp5['menu_link']=$_GPC['menu_link_five'];
				$temp5['menu_pic']=$_GPC['menu_pic_five'];
				$temp5['menu_display'] =1;
				array_push($menu,$temp5);
			}
			if($_GPC['menu_display_six'] == 0){
				$temp6['menu_name']=$_GPC['menu_name_six'];
				$temp6['menu_link']=$_GPC['menu_link_six'];
				$temp6['menu_pic']=$_GPC['menu_pic_six'];
				$temp6['menu_display'] =1;
				array_push($menu,$temp6);
			}
			$menu_info=serialize($menu);
			if(pdo_update('ly_fxhd_activity',array('bottom_hrefs'=>$menu_info),array('uniacid'=>$_W['uniacid'],'id'=>$_GPC['act_id']))){
				message('操作成功',$this->createWebUrl('product'),'success');
			}else{
				message('操作失败','','error');
			}
		}
		$oneactivity=pdo_get("ly_fxhd_activity",array("id"=>$_GPC['aid'],'uniacid'=>$_W['uniacid']));
		$menu_info =unserialize($oneactivity['bottom_hrefs']);
		include $this->template('edit_menu');
	}
	
	//提现管理
	public function doWebRebate_mag(){
		global $_W,$_GPC;
	
		$page = max(1, intval($_GPC['page']));
		$psize = 20;
		$_GPC['op']? $operation = $_GPC['op'] : $operation = 'wait';
		if(checksubmit()){
			if($operation == 'wait')
				$list=pdo_fetchall('SELECT u.nickname,u.avatar,u.id AS userid,u.phone,r.* FROM ims_ly_fxhd_apply_rebate AS r LEFT JOIN ims_ly_fxhd_users AS u ON u.id = r.userid WHERE r.uniacid='.$_W['uniacid'].' AND r.status = 0 and (u.phone like "%'.$_GPC['keyword'].'%" or u.name like "%'.$_GPC['keyword'].'%")');
			elseif($operation =='success')
				$list=pdo_fetchall('SELECT u.nickname,u.avatar,u.id AS userid,u.phone,r.* FROM ims_ly_fxhd_apply_rebate AS r LEFT JOIN ims_ly_fxhd_users AS u ON u.id = r.userid WHERE r.uniacid='.$_W['uniacid'].' AND r.status = 1 and (u.phone like "%'.$_GPC['keyword'].'%" or u.name like "%'.$_GPC['keyword'].'%")');
			elseif($operation == "error")
				$list=pdo_fetchall('SELECT u.nickname,u.avatar,u.id AS userid,u.phone,r.* FROM ims_ly_fxhd_apply_rebate AS r LEFT JOIN ims_ly_fxhd_users AS u ON u.id = r.userid WHERE r.uniacid='.$_W['uniacid'].' AND r.status = 2 and (u.phone like "%'.$_GPC['keyword'].'%" or u.name like "%'.$_GPC['keyword'].'%")');
			$total=count($list);
		}else{
			if($operation == 'wait'){
				$sql = 'SELECT u.nickname,u.avatar,u.id AS userid,u.phone,r.* FROM ims_ly_fxhd_apply_rebate AS r LEFT JOIN ims_ly_fxhd_users AS u ON u.id = r.userid WHERE r.status = 0 AND r.uniacid='.$_W['uniacid'].' order by r.id desc LIMIT ' . ($page - 1) * $psize . ",{$psize}";
				$list = pdo_fetchall($sql);
				$tol = pdo_fetchcolumn('select COUNT(*) from ims_ly_fxhd_apply_rebate where uniacid='.$_W['uniacid'].' and status = 0');
			}elseif($operation =='success'){
				$sql = 'SELECT u.nickname,u.avatar,u.id AS userid,u.phone,r.* FROM ims_ly_fxhd_apply_rebate AS r LEFT JOIN ims_ly_fxhd_users AS u ON u.id = r.userid WHERE r.status = 1 AND r.uniacid='.$_W['uniacid'].' order by r.id desc LIMIT ' . ($page - 1) * $psize . ",{$psize}";
				$list = pdo_fetchall($sql);
				$tol = pdo_fetchcolumn('select COUNT(*) from ims_ly_fxhd_apply_rebate where uniacid='.$_W['uniacid'].' and status = 1');
			}elseif($operation =='error'){
				$sql = 'SELECT u.nickname,u.avatar,u.id AS userid,u.phone,r.* FROM ims_ly_fxhd_apply_rebate AS r LEFT JOIN ims_ly_fxhd_users AS u ON u.id = r.userid WHERE r.status = 2 AND r.uniacid='.$_W['uniacid'].' order by r.id desc LIMIT ' . ($page - 1) * $psize . ",{$psize}";
				$list = pdo_fetchall($sql);
				$tol = pdo_fetchcolumn('select COUNT(*) from ims_ly_fxhd_apply_rebate where uniacid='.$_W['uniacid'].' and status = 2');
			}
			$pager = pagination($tol, $page, $psize);
		}
		include $this->template('rebate_mag');
	}

	//红包管理
	public function doWebRed_mag(){
		global $_W,$_GPC;
	
		$page = max(1, intval($_GPC['page']));
		$psize = 20;
		$_GPC['op']? $operation = $_GPC['op'] : $operation = 'wait';
		if(checksubmit()){
			if($operation == 'wait')
				$list=pdo_fetchall('SELECT u.nickname,u.avatar,u.id AS userid,u.phone,r.* FROM ims_ly_fxhd_send_packet AS r LEFT JOIN ims_ly_fxhd_users AS u ON u.id = r.userid WHERE r.uniacid='.$_W['uniacid'].' AND r.status = 0 and (u.phone like "%'.$_GPC['keyword'].'%" or u.name like "%'.$_GPC['keyword'].'%")');
			elseif($operation =='success')
				$list=pdo_fetchall('SELECT u.nickname,u.avatar,u.id AS userid,u.phone,r.* FROM ims_ly_fxhd_send_packet AS r LEFT JOIN ims_ly_fxhd_users AS u ON u.id = r.userid WHERE r.uniacid='.$_W['uniacid'].' AND r.status = 1 and (u.phone like "%'.$_GPC['keyword'].'%" or u.name like "%'.$_GPC['keyword'].'%")');
			elseif($operation == "error")
				$list=pdo_fetchall('SELECT u.nickname,u.avatar,u.id AS userid,u.phone,r.* FROM ims_ly_fxhd_send_packet AS r LEFT JOIN ims_ly_fxhd_users AS u ON u.id = r.userid WHERE r.uniacid='.$_W['uniacid'].' AND r.status = 2 and (u.phone like "%'.$_GPC['keyword'].'%" or u.name like "%'.$_GPC['keyword'].'%")');
			$total=count($list);
		}else{
			if($operation == 'wait'){
				$sql = 'SELECT u.nickname,u.avatar,u.id AS userid,u.phone,r.* FROM ims_ly_fxhd_send_packet AS r LEFT JOIN ims_ly_fxhd_users AS u ON u.id = r.userid WHERE r.status = 0 AND r.uniacid='.$_W['uniacid'].' order by r.id desc LIMIT ' . ($page - 1) * $psize . ",{$psize}";
				$list = pdo_fetchall($sql);
				$tol = pdo_fetchcolumn('select COUNT(*) from ims_ly_fxhd_send_packet where uniacid='.$_W['uniacid'].' and status = 0');
			}elseif($operation =='success'){
				$sql = 'SELECT u.nickname,u.avatar,u.id AS userid,u.phone,r.* FROM ims_ly_fxhd_send_packet AS r LEFT JOIN ims_ly_fxhd_users AS u ON u.id = r.userid WHERE r.status = 1 AND r.uniacid='.$_W['uniacid'].' order by r.id desc LIMIT ' . ($page - 1) * $psize . ",{$psize}";
				$list = pdo_fetchall($sql);
				$tol = pdo_fetchcolumn('select COUNT(*) from ims_ly_fxhd_send_packet where uniacid='.$_W['uniacid'].' and status = 1');
			}elseif($operation =='error'){
				$sql = 'SELECT u.nickname,u.avatar,u.id AS userid,u.phone,r.* FROM ims_ly_fxhd_send_packet AS r LEFT JOIN ims_ly_fxhd_users AS u ON u.id = r.userid WHERE r.status = 2 AND r.uniacid='.$_W['uniacid'].' order by r.id desc LIMIT ' . ($page - 1) * $psize . ",{$psize}";
				$list = pdo_fetchall($sql);
				$tol = pdo_fetchcolumn('select COUNT(*) from ims_ly_fxhd_send_packet where uniacid='.$_W['uniacid'].' and status = 2');
			}
			$pager = pagination($tol, $page, $psize);
		}
		include $this->template('red_mag');
	}
	//后台返现
	public function doWebRebate_detail(){
		global $_W,$_GPC;

		if($_W['isajax']){
			//找返利的人的openid
			$rebate_openid = pdo_get('ly_fxhd_users',array('uniacid'=>$_W['uniacid'],'id'=>$_GPC['userid']))['openid'];
			//调用退款函数
			$status = $this->sendRedPacket($rebate_openid,$_GPC['fee']*100);
			if($status === true){
				if($_GPC['op'] == 'red'){
					pdo_update('ly_fxhd_send_packet',array('status'=>1),array('uniacid'=>$_W['uniacid'],'id'=>$_GPC['rid']));
					$resArr['code'] = 0;
				}else{
					pdo_update('ly_fxhd_apply_rebate',array('status'=>1),array('uniacid'=>$_W['uniacid'],'id'=>$_GPC['rid']));
					$resArr['code'] = 0;
				}	
			}else{
				if($_GPC['op'] == 'red'){
					pdo_update('ly_fxhd_send_packet',array('status'=>2),array('uniacid'=>$_W['uniacid'],'id'=>$_GPC['rid']));
					$resArr['code'] = 1;
				}else{
					pdo_update('ly_fxhd_apply_rebate',array('status'=>2),array('uniacid'=>$_W['uniacid'],'id'=>$_GPC['rid']));
					$resArr['code'] = 1;
				}
				
			}
			$resArr['btn'] = $_GPC['rid'];
			echo json_encode($resArr);exit;
		}
	}

	public function doMobileShare_action(){
		global $_W,$_GPC;
		$cr = pdo_get('ly_fxhd_copyright',array('uniacid'=>$_W['uniacid']));
		if($_W['isajax']){
			//	查找活动红包发送方式
			$act_info = pdo_get('ly_fxhd_activity',array('uniacid'=>$_W['uniacid'],'id'=>$_GPC['aid']));
			$userid = pdo_get('ly_fxhd_users',array('uniacid'=>$_W['uniacid'],'openid'=>$_W['openid']))['id'];
			//判断此活动是否已经发过红包
			$is_send = pdo_get('ly_fxhd_send_packet',array('uniacid'=>$_W['uniacid'],'userid'=>$userid,'actid'=>$_GPC['aid']));
			if(!$is_send){
				//判断是否超出红包的总金额数
				$red_count = pdo_fetchcolumn('SELECT sum(fee) FROM ims_ly_fxhd_send_packet WHERE uniacid='.$_W['uniacid'].' AND actid='.$_GPC['aid'].' AND status =1 or status =2');
				if($red_count < $act_info['red_count']){
					//是否即时发送
					$money = round(($act_info['red_min'] + mt_rand() / mt_getrandmax() * ($act_info['red_max'] - $act_info['red_min'])), 2);
					if($act_info['red_kind'] == 0){
						$status = $this->sendRedPacket($_W['openid'],$money*100);
						if($status === true){
							pdo_insert('ly_fxhd_send_packet',array('uniacid'=>$_W['uniacid'],'userid'=>$userid,'actid'=>$_GPC['aid'],'fee'=>$money,'status'=>1,'insert_time'=>time()));
							$resArr['code'] = 0;
						}else{
							$resArr['code'] = 1;
						}
					}else{
						pdo_insert('ly_fxhd_send_packet',array('uniacid'=>$_W['uniacid'],'userid'=>$userid,'actid'=>$_GPC['aid'],'fee'=>$money,'status'=>0,'insert_time'=>time()));
						$resArr['code'] = 2;
					}
				}else{
					$resArr['code'] = 3;
				}
			}else{
				$resArr['code'] = 4;
			}
			echo json_encode($resArr);exit;
		}
	}

	//商家发布
	public function doMobileAct_register(){
		global $_W,$_GPC;

		if($_W['ispost']){
			$data =array(
				'uniacid'=>$_W['uniacid'],
				'name'=>$_GPC['shop_name'],
				'username'=>$_GPC['mag_name'],
				'phone'=>$_GPC['mag_phone'],
				'address'=>$_GPC['shop_address'],
				'introduce'=>$_GPC['detail'],
				'type'=> -1
			);
			$res = pdo_insert('ly_fxhd_infos',$data);
			if($res){
				message('发布成功','','success');
			}else{
				message('发布失败','','error');
			}
		}
		$cr = pdo_get('ly_fxhd_copyright',array('uniacid'=>$_W['uniacid']));
		include $this->template('act_register');
	}
	//订单管理主页
	public function doMobileOrder_main(){
		global $_W,$_GPC;

		//店铺
		$shop = pdo_get('ly_fxhd_shops',array('uniacid'=>$_W['uniacid'],'openid'=>$_W['openid']));
		if(empty($shop)){
			message('无查看权限','','error');
		}else{
			//查找店铺内的活动
			$sql = 'SELECT a.id as aid,a.end_time,a.start_time,g.banner,a.title,a.orig_price,a.true_price,a.sketch FROM ims_ly_fxhd_activity AS a LEFT JOIN ims_ly_fxhd_goods AS g ON a.goodid = g.id LEFT JOIN ims_ly_fxhd_shops AS s ON g.shopid = s.id WHERE a.uniacid = '.$_W['uniacid'].' AND s.id = '.$shop['id'].' ORDER BY a.id DESC';
			$act_list = pdo_fetchall($sql);
		}
		$cr = pdo_get('ly_fxhd_copyright',array('uniacid'=>$_W['uniacid']));
		include $this->template('order_main');
	}
	//订单管理
	public function doMobileOrder_mag(){
		global $_W,$_GPC;
		
		if($_W['isajax']){
			
			if($_GPC['op'] == 'rebate'){		
				//购买人
				$self = pdo_get('ly_fxhd_orders',array('uniacid'=>$_W['uniacid'],'id'=>$_GPC['orderid']))['userid'];
				//上级id
				$one_id = pdo_get('ly_fxhd_superior',array('userid'=>$self,'artid'=>$_GPC['actid']))['fatherid'];
	
				//一级返利
				if(!empty($one_id)){
					$sql = 'SELECT * FROM ims_ly_fxhd_rebate AS r LEFT JOIN ims_ly_fxhd_users AS u ON r.user_id = u.id WHERE r.uniacid = '.$_W['uniacid'].' AND r.orderid = '.$_GPC['orderid'].' AND r.user_id = '.$one_id;
					$temp = pdo_fetch($sql);
					if(!empty($temp))
						$temp['time'] = date('Y-m-d H:m',$temp['insert_time']);
					$resArr['one_level'] =  $temp;	
					//二级
					$two_id = pdo_get('ly_fxhd_superior',array('userid'=>$one_id,'artid'=>$_GPC['actid']))['fatherid'];
					if(!empty($two_id)){
						$sql1 = 'SELECT * FROM  ims_ly_fxhd_rebate AS r LEFT JOIN ims_ly_fxhd_users AS u ON r.user_id = u.id WHERE r.uniacid = '.$_W['uniacid'].' AND r.orderid = '.$_GPC['orderid'].' AND r.user_id = '.$two_id;
						$temp1 = pdo_fetch($sql1);
						if(!empty($temp1))
							$temp1['time'] = date('Y-m-d H:m',$temp1['insert_time']);
						$resArr['two_level'] =  $temp1;
						//三级
						$three_id = pdo_get('ly_fxhd_superior',array('userid'=>$two_id,'artid'=>$_GPC['actid']))['fatherid'];
						if(!empty($three_id)){
							$sql2 = 'SELECT * FROM ims_ly_fxhd_rebate AS r LEFT JOIN ims_ly_fxhd_users AS u ON r.user_id = u.id WHERE r.uniacid = '.$_W['uniacid'].' AND r.orderid = '.$_GPC['orderid'].' AND r.user_id = '.$three_id;
							$temp2 = pdo_fetch($sql2);
							if(!empty($temp2))
								$temp2['time'] = date('Y-m-d H:m',$temp2['insert_time']);
							$resArr['three_level'] =  $temp2;
						}else{
							$resArr['three_level'] =  '';
						}
					}else{
						$resArr['two_level'] =  '';
						$resArr['three_level'] =  '';
					}
				}else{
					$resArr['one_level'] =  '';
					$resArr['two_level'] =  '';
					$resArr['three_level'] =  '';
				}
			}elseif($_GPC['op'] == 'take'){
				if(pdo_update('ly_fxhd_orders',array('is_take'=>1),array('uniacid'=>$_W['uniacid'],'id'=>$_GPC['orderid']))){
					$resArr['code'] =0;
				}else{
					$resArr['code'] =1;
				}		
			}elseif($_GPC['op'] == 'remark'){

				if(pdo_update('ly_fxhd_orders',array('remark'=>$_GPC['value']),array('uniacid'=>$_W['uniacid'],'id'=>$_GPC['orderid']))){
					$resArr['code'] =0;
				}else{
					$resArr['code'] =1;
				}		
			}else{
				$limit = $_GPC['limit'];
				$page = $_GPC['page'];
				$temp = array();
				$sql = 'SELECT o.id AS id,u.name,o.address,o.remark,o.mode,u.phone,o.activityid,o.is_take FROM ims_ly_fxhd_orders AS o LEFT JOIN ims_ly_fxhd_users AS u ON o.userid = u.id WHERE o.uniacid = '.$_W['uniacid'].' AND o.activityid ='.$_GPC['actid'].' AND o.type = 1 ORDER BY o.id DESC';

				$order_list = pdo_fetchall($sql);
	            $fee_total = pdo_fetchcolumn('SELECT SUM(price) FROM ims_ly_fxhd_orders WHERE uniacid = '.$_W['uniacid'].' AND activityid ='.$_GPC['actid'].' and type=1');
				$temp = array_slice($order_list,($page-1)*$limit,20);
				$resArr['code'] = 0;
				$resArr['msg'] = round($fee_total,2);
				$resArr['count'] = count($order_list);
				$resArr['data'] = $temp;
			}
			
			echo json_encode($resArr);
			exit;
		}
		$cr = pdo_get('ly_fxhd_copyright',array('uniacid'=>$_W['uniacid']));
		include $this->template('order_mag');
	}

	//机器人订单
	public function doWebRobot_order(){
		global $_W,$_GPC;

		// 如果没有创建活动不能操作
		if(empty($_GPC['aid'])){
			message('请先创建完活动',$this->createWebUrl('Product'),'error');
		}
		if(checksubmit()){
			$data=array(
				'uniacid'=>$_W['uniacid'],
				'nickname'=>$_GPC['nickname'],
				'phone'=>$_GPC['phone'],
				'avatar'=>tomedia($_GPC['avatar']),
				'pay_time'=>strtotime($_GPC['pay_time']),
				'activityid'=>$_GPC['aid']
			);
			if(pdo_insert('ly_fxhd_robot_order',$data)){
				message('录入成功',$this->createWebUrl('robot_order',array('aid'=>$_GPC['aid'])),'success');
			}else{
				message('录入失败',$this->createWebUrl('robot_order',array('aid'=>$_GPC['aid'])),'error');
			}
		}
		if($_GPC['op'] == 'del'){
			if(pdo_delete('ly_fxhd_robot_order',array('uniacid'=>$_W['uniacid'],'id'=>$_GPC['oid']))){
				message('删除成功',$this->createWebUrl('robot_order',array('aid'=>$_GPC['aid'])),'success');
			}else{
				message('删除失败',$this->createWebUrl('robot_order',array('aid'=>$_GPC['aid'])),'success');
			}
		}
		$robot_list = pdo_getall('ly_fxhd_robot_order',array('uniacid'=>$_W['uniacid'],'activityid'=>$_GPC['aid']));

		include $this->template('robot_order');
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

	public function doWebOrder_web_mag(){
		global $_W,$_GPC;
		/**
		 * 列出所有活动
		 */
		$act_list = pdo_getall('ly_fxhd_activity',array('uniacid'=>$_W['uniacid']),array(),'','id DESC');

		include $this->template('order_web_mag');
	}

	public function doWebOrder_detail(){
		global $_W,$_GPC;
		if($_W['isajax']){
			//购买人
			$self = pdo_get('ly_fxhd_orders',array('uniacid'=>$_W['uniacid'],'id'=>$_GPC['orderid']))['userid'];
			//上级id
			$one_id = pdo_get('ly_fxhd_superior',array('userid'=>$self,'artid'=>$_GPC['actid']))['fatherid'];

			//一级返利
			if(!empty($one_id)){
				$sql = 'SELECT u.nickname,u.phone,u.avatar,r.insert_time,r.fee FROM ims_ly_fxhd_rebate AS r LEFT JOIN ims_ly_fxhd_users AS u ON r.user_id = u.id WHERE r.uniacid = '.$_W['uniacid'].' AND r.orderid = '.$_GPC['orderid'].' AND r.user_id = '.$one_id;
				$temp = pdo_fetch($sql);
				if(!empty($temp)){
					$temp['time'] = date('Y-m-d H:m',$temp['insert_time']);
				}
				$resArr['one_level'] =  $temp;	
	
				//二级
				$two_id = pdo_get('ly_fxhd_superior',array('userid'=>$one_id,'artid'=>$_GPC['actid']))['fatherid'];
				if(!empty($two_id)){
					$sql1 = 'SELECT u.nickname,u.phone,u.avatar,r.insert_time,r.fee FROM  ims_ly_fxhd_rebate AS r LEFT JOIN ims_ly_fxhd_users AS u ON r.user_id = u.id WHERE r.uniacid = '.$_W['uniacid'].' AND r.orderid = '.$_GPC['orderid'].' AND r.user_id = '.$two_id;
					$temp1 = pdo_fetch($sql1);
					if(!empty($temp1)){
						$temp1['time'] = date('Y-m-d H:m',$temp1['insert_time']);
					}
					$resArr['two_level'] =  $temp1;

					//三级
					$three_id = pdo_get('ly_fxhd_superior',array('userid'=>$two_id,'artid'=>$_GPC['actid']))['fatherid'];
					if(!empty($three_id)){
						$sql2 = 'SELECT u.nickname,u.phone,u.avatar,r.insert_time,r.fee FROM ims_ly_fxhd_rebate AS r LEFT JOIN ims_ly_fxhd_users AS u ON r.user_id = u.id WHERE r.uniacid = '.$_W['uniacid'].' AND r.orderid = '.$_GPC['orderid'].' AND r.user_id = '.$three_id;
						$temp2 = pdo_fetch($sql2);
						if(!empty($temp2)){
							$temp2['time'] = date('Y-m-d H:m',$temp2['insert_time']);
						}	
						$resArr['three_level'] =  $temp2;
					}else{
						$resArr['three_level'] =  '';
					}
				}else{
					$resArr['two_level'] =  '';
					$resArr['three_level'] =  '';
				}
			}else{
				$resArr['one_level'] =  '';
				$resArr['two_level'] =  '';
				$resArr['three_level'] =  '';
			}
			echo json_encode($resArr);exit;
		}

		if(!empty($_GPC['aid'])){
			$sql = 'SELECT o.id AS id,u.name,u.avatar,o.price,o.pay_time,u.phone,o.activityid,o.is_take,o.mode FROM ims_ly_fxhd_orders AS o LEFT JOIN ims_ly_fxhd_users AS u ON o.userid = u.id WHERE o.uniacid = '.$_W['uniacid'].' AND o.activityid ='.$_GPC['aid'].' AND o.type = 1 ORDER BY o.id DESC';
			$order_list = pdo_fetchall($sql);
		}
		include $this->template('order_detail');
	}
	// 用户管理
	public function doWebUsers_mag(){
		global $_W,$_GPC;

		$page = max(1, intval($_GPC['page']));
		$psize = 20;
		if(checksubmit()){
			$list= pdo_fetchall('SELECT * FROM  ims_ly_fxhd_users  WHERE uniacid='.$_W['uniacid'].' AND (phone like "%'.$_GPC['keyword'].'%" or name like "%'.$_GPC['keyword'].'%")');
			$total = count($list);
		}else{
			$sql = 'SELECT * FROM ims_ly_fxhd_users WHERE uniacid='.$_W['uniacid'].' order by id desc LIMIT ' . ($page - 1) * $psize . ",{$psize}";
			$list = pdo_fetchall($sql);
			$tol = pdo_fetchcolumn('select COUNT(*) from ims_ly_fxhd_users where uniacid='.$_W['uniacid']);
			$pager = pagination($tol, $page, $psize);
		}	
		include $this->template('users_mag');
	}
	//发送红包
	private function sendRedPacket($openid,$money){	//封装好的微信发红包函数
		global $_W,$_GPC;
		load()->func('logging');
		$url = 'https://api.mch.weixin.qq.com/mmpaymkttransfers/sendredpack';
		load()->func('communication');
		$pars = array();

		$api = $this->module['config'];
		$cfg = $api['api'];
		$pars['nonce_str'] = random(32);
		$pars['mch_billno'] = $cfg['mchid'] . date('YmdHis') . rand( 100, 999 );//订单号
		$pars['mch_id'] = $cfg['mchid'];
		$pars['wxappid'] = $cfg['appid'];
		$pars['send_name'] = $cfg['send_name'];
		$pars['re_openid'] = $openid;
		$pars['total_amount'] = $money;
		$pars['total_num'] = 1;
		$pars['wishing'] = $cfg['wishing'];
		$pars['client_ip'] = $_W['clientip'];
		$pars['act_name'] = $cfg['act_name'];
		$pars['remark'] = $cfg['remark'];
		ksort($pars, SORT_STRING);
		$string1 = '';
		foreach($pars as $k => $v) {
			$string1 .= "{$k}={$v}&";
		}
		$string1 .= "key={$cfg['password']}";
		$pars['sign'] = strtoupper(md5($string1));
		$xml = array2xml($pars);
		$extras = array();
		$extras['CURLOPT_CAINFO']   =IA_ROOT .'/addons/ly_fenxiaohuodong/cert/rootca.pem';
		$extras['CURLOPT_SSLCERT'] =IA_ROOT .'/addons/ly_fenxiaohuodong/cert/apiclient_cert.pem';
		$extras['CURLOPT_SSLKEY']  =IA_ROOT .'/addons/ly_fenxiaohuodong/cert/apiclient_key.pem';

		$procResult = false;
		$resp = ihttp_request($url, $xml, $extras);
		if(is_error($resp)) {
			$setting = $this->module['config'];
			$setting['api']['error'] = $resp['message'];
			$this->saveSettings($setting);
			$procResult = $resp['message'];
		} else {
			$xml = '<?xml version="1.0" encoding="utf-8"?>' . $resp['content'];
			$dom = new DOMDocument();
			if($dom->loadXML($xml)) {
				$xpath = new DOMXPath($dom);
				$code = $xpath->evaluate('string(//xml/return_code)');
				$ret = $xpath->evaluate('string(//xml/result_code)');
				if(strtolower($code) == 'success' && strtolower($ret) == 'success') {
					$procResult = true;
					$setting = $this->module['config'];
					$setting['api']['error'] = '';
					$this->saveSettings($setting);
				} else {
					$error = $xpath->evaluate('string(//xml/err_code_des)');
					$setting = $this->module['config'];
					$setting['api']['error'] = $error;
					$this->saveSettings($setting);
					$procResult = $error;
				}
			} else {
				$procResult = 'error response';
			}
		}
		logging_run('结果'.json_encode($resp['content']),'info','99999999999');
		// fwrite($myfile, $resp['content']);
		// fclose($myfile);
		return $procResult;
	}

	public function images($actid){
		global $_W,$_GPC;
		$url_shop="http://{$_SERVER['SERVER_NAME']}/app/index.php?i=".$_W['uniacid'];
		$imgs2=str_replace('&','%26','%26c=entry%26do=art%26m=ly_fenxiaohuodong%26artid='.$actid);
		$imgs="http://b.bshare.cn/barCode?site=weixin&url=".$url_shop.$imgs2;
		return $imgs;
	}
}