<?php


	/*
	 * 商品控制器
	 *
	 *
	*/

	class goods extends common{


		private $_goodsModel = null;
		private $_redis = null;

		/*
		 * 构造器
		 *
		*/
		public function __construct(){

			if($this->_goodsModel === null){
				$this->_goodsModel = new GoodsModel();
			}

			if($this->_redis === null){
				$this->_redis = new QRedis();
			}
			
		}


		/*
		 * 查看商品列表
		 *
		*/
		public function goodsLits(){
			$list 	= $this->_goodsModel->getGoodses();
			$redis 	= $this->_redis;
			foreach ($list as $key => &$value) {
				$id		= $value['id'];
				$key	= 'goods_list_'.$id;
				$count 	= $redis->listcount($key);
				$value['rediscount'] = $count;
			}
			$this->render('',['list'=>$list]);
		}



		/*
		 * 设置商品库存
		 *
		*/
		public function setGoodsCount(){

			$gid 		= $_POST['gid'];
			$count 		= $_POST['counts'];
			$goods_Res 	= $this->_goodsModel->getGoods($gid);

			if($goods_Res){

				$id = $goods_Res['id'];
				$result = $this->_goodsModel->setGoodsCount($id,$count);
				
				if($result){
					//更新redis list
					$redis = $this->_redis;
					$key = 'goods_list_'.$id;
					$redis->clearlist($key);//清空redis队列
					for($i=1;$i<=$count;$i++){//循环将商品的剩余数量一个一个加入队列
						$redis->addRlist($key,1);
					}
					$this->ajaxreturn(['status'=>1,'info'=>'编辑成功']);
				}
			}
			$this->ajaxreturn(['status'=>0,'info'=>'编辑失败']);
		}
	}