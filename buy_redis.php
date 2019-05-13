<?php
	
	$url = 'www.test.com/redis_seckill/index.php?app=app&c=seckill&a=addQsec&gid=1&type=redis';
	$result = file_get_contents($url);
	
	var_dump($result);
