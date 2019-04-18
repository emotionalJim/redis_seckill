<?php

class LogModel extends Model{

    public function add($data){
        $sql = 'insert into kill_log (goods_id,num,des,add_time) values (' . $data['goods_id'] . ',' . $data['num'] . ',"' . $data['des'] . '",' . $data['add_time'] . ')';
        $result = $this->exect($sql);
        return $result;
    }
}