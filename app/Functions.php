<?php

class Functions extends TelegramApp\Module {
	protected $runCommands = FALSE;

	protected function hooks(){}
	public function run(){ return; }

	public function message_assign_set($mid, $chat = NULL, $user = NULL){
		if(is_array($mid)){
			if(empty($user) and !empty($chat)){
				$user = $chat;
				$chat = $mid['chat']['id'];
				$mid = $mid['message_id'];
			}elseif(empty($chat) and empty($user)){
				$user = $mid['from']['id'];
				$chat = $mid['chat']['id'];
				$mid = $mid['message_id'];
			}
		}
		if(!$mid){ return FALSE; }

		$data = [
			'mid' => $mid,
			'cid' => $chat,
			'target' => $user,
			'date' => $this->db->now(),
		];

		$id = $this->db->insert('user_message_id', $data);
		// TODO Cache
		// $key = 'message_assign_' .md5($mid .$chat);
		// $this->cache->save($key, $user, 3600*24);
		return $id;
	}

	public function message_assign_get($mid = NULL, $chat = NULL){
		// mirar si hay reply y llamar directamente
		if(empty($mid)){
			if(!$this->telegram->has_reply){ return FALSE; }
			$mid = $this->telegram->reply->message_id;
			$chat = $this->chat->id;
		}

		if($chat instanceof Chat){ $chat = $chat->id; }
		// TODO Cache
		// $key = 'message_assign_' .md5($mid .$chat);
		// $cache = $this->cache->get($key);
		// if($cache){ return $cache; }
		$uid = $this->db
			->where('mid', $mid)
			->where('cid', $chat)
		->getValue('user_message_id', 'target');
		return $uid;
	}

	// INFO:
	// Hay dos funciones diferentes para calcular la distancia.
	// Una es más precisa si la distancia es más pequeña de 500m aprox.
	// La otra conviene más para distancias largas.
	// Tener en cuenta que es distancia en linea recta.

	function location_distance($locA, $locB, $locC = NULL, $locD = NULL){
		$earth = 6371000;
		if($locC !== NULL && $locD !== NULL){
			$locA = [$locA, $locB];
			$locB = [$locC, $locD];
		}
		$locA[0] = deg2rad($locA[0]);
		$locA[1] = deg2rad($locA[1]);
		$locB[0] = deg2rad($locB[0]);
		$locB[1] = deg2rad($locB[1]);
	
		$latD = $locB[0] - $locA[0];
		$lonD = $locB[1] - $locA[1];
	
		$angle = 2 * asin(sqrt(pow(sin($latD / 2), 2) + cos($locA[0]) * cos($locB[0]) * pow(sin($lonD / 2), 2)));
		return ($angle * $earth);
	}
	
	function location_add($locA, $locB, $amount = NULL, $direction = NULL){
		// if(is_object($locA)){ $locA = [$locA->latitude, $locA->longitude]; }
		if(!is_array($locA) && $direction === NULL){ return FALSE; }
		if(!is_array($locA)){ $locA = [$locA, $locB]; }
		// si se rellenan 3 y direction es NULL, entonces locA es array.
		if(is_numeric($locB) && $amount !== NULL && $direction === NULL){
			$direction = $amount;
			$amount = $locB;
		}
		$direction = strtoupper($direction);
		$steps = [
			'N' => ['NORTE', 'NORTH', 'N', 'UP'],
			'NW' => ['NOROESTE', 'NORTHWEST', 'NW', 'UP_LEFT'],
			'NE' => ['NORESTE', 'NORTHEAST', 'NE', 'UP_RIGHT'],
			'S' => ['SUD', 'SOUTH', 'S', 'DOWN'],
			'SW' => ['SUDOESTE', 'SOUTHWEST', 'SW', 'DOWN_LEFT'],
			'SE' => ['SUDESTE', 'SOUTHEAST', 'SE', 'DOWN_RIGHT'],
			'W' => ['OESTE', 'WEST', 'W', 'O', 'LEFT'],
			'E' => ['ESTE', 'EAST', 'E', 'RIGHT']
		];
		foreach($steps as $s => $k){ if(in_array($direction, $k)){ $direction = $s; break; } } // Buscar y asociar dirección
		$earth = (40075 / 360 * 1000);
		$cal = ($amount / $earth);
	
		foreach(str_split($direction) as $dir){
			if($dir == 'N'){ $locA[0] = $locA[0] + $cal; }
			elseif($dir == 'S'){ $locA[0] = $locA[0] - $cal; }
			elseif($dir == 'W'){ $locA[1] = $locA[1] - $cal; }
			elseif($dir == 'E'){ $locA[1] = $locA[1] + $cal; }
		}
	
		return $locA;
	}
}