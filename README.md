# redis秒杀系统
## 环境
* php5.6 + phpredis扩展
* redis服务
* apache2
* mysql：table 商品表(goods) + 订单表（order）

## 工作流程
1. 创建商品表、订单表和日志表（data目录）
2. 先打开redis，访问http://**your_site**/redis_seckill/?app=app&c=goods&a=goodsLits 设置商品的redis库存缓存
2. 客户端访问秒杀API(可用Apache ab测试  url路径保存在buy_redis.php文件中)
3. **先从redis的商品库存队列中查询剩余库存**
4. redis队列中有剩余，则在mysql中创建订单，去库存，抢购成功
5. redis队列中没有剩余，则提示库存不足，抢购失败
