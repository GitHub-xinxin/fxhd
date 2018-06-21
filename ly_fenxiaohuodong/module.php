<?php
/**
 * 趣闻实验模块定义
 *
 * @author ly_dicyan
 * @url http://bbs.we7.cc/
 */
defined('IN_IA') or exit('Access Denied');

class Ly_fenxiaohuodongModule extends WeModule {
	public function fieldsFormDisplay($rid = 0) {
		//要嵌入规则编辑页的自定义内容，这里 $rid 为对应的规则编号，新增时为 0
	}

	public function fieldsFormValidate($rid = 0) {
		//规则编辑保存时，要进行的数据验证，返回空串表示验证无误，返回其他字符串将呈现为错误提示。这里 $rid 为对应的规则编号，新增时为 0
		return '';
	}

	public function fieldsFormSubmit($rid) {
		//规则验证无误保存入库时执行，这里应该进行自定义字段的保存。这里 $rid 为对应的规则编号
	}

	public function ruleDeleted($rid) {
		//删除规则时调用，这里 $rid 为对应的规则编号
	}

	public function settingsDisplay($settings) {
		global $_W, $_GPC;
		//点击模块设置时将调用此方法呈现模块设置页面，$settings 为模块设置参数, 结构为数组。这个参数系统针对不同公众账号独立保存。
		//在此呈现页面中自行处理post请求并保存设置参数（通过使用$this->saveSettings()来实现）
		if(checksubmit()) {
			load()->func('file');
			
			mkdirs(OD_ROOT . '/cert');
			
			$r = true;
			
			if(!empty($_GPC['cert'])) {// 商户支付证书
			
				$ret = file_put_contents(OD_ROOT . "/".md5("apiclient_{$_W['uniacid']}cert").".pem", trim($_GPC['cert']));
			
				$r = $r && $ret;
			
			}
			
			if(!empty($_GPC['key'])) {//支付证书私匙
			
				$ret = file_put_contents(OD_ROOT . "/".md5("apiclient_{$_W['uniacid']}key").".pem", trim($_GPC['key']));
			
				$r = $r && $ret;
			
			}
			
			if(!empty($_GPC['ca'])) {//支付根证书
			
				$ret = file_put_contents(OD_ROOT . "/".md5("root{$_W['uniacid']}ca").".pem", trim($_GPC['ca']));
			
				$r = $r && $ret;
			
			}
			
			if(!$r) {
			
				message('证书保存失败, 请保证 '.OD_ROOT.' 目录可写');
			
			}
			
			$dat['api'] = array(
			
					'mchid'=>$_GPC['mchid'],
			
					'password'=>$_GPC['password'],
						
					'appid'=>$_GPC['appid'],
					
					'secret'=>$_GPC['secret'],
					'act_name' => $_GPC['act_name'],
					'wishing' => $_GPC['wishing'],
					'remark' => $_GPC['remark'],
					'send_name' => $_GPC['send_name'],
			);
			//字段验证, 并获得正确的数据$dat
			$this->saveSettings($dat);			
		}
		$config = $settings['api'];				
		//这里来展示设置项表单
		include $this->template('setting');
	}

}