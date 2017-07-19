<?php

if($this->telegram->is_chat_group()){

	if($this->telegram->text_has(["montar", "monta", "crear", "organizar", "organiza"], ["raid", "incursión"])){
		$place = NULL;
		if($this->telegram->text_has("en")){
			$pos = strpos($this->telegram->text(), " en ") + strlen(" en ");
			$place = substr($this->telegram->text(), $pos);
		}
		if(empty($place) and $this->telegram->words() > 5){ return; }

		$poke = pokemon_parse($this->telegram->text());
		$time = time_parse($this->telegram->text());

		$str = "Nueva #raid";

		if(!empty($poke) and isset($poke['pokemon'])){
			$pokedex = $pokemon->pokedex($poke['pokemon']);
			$str .= " de " .$pokedex->name;
		}

		if(!empty($time) and isset($time['hour'])){
			$str .= " a las " .$time['hour'];
		}

		if(!empty($place)){
			$str .= " en $place";
		}

		$str .= "!\n";

		$user = $pokemon->user($this->telegram->user->id);
		$team = ['R' => 'red', 'B' => 'blue', 'Y' => 'yellow'];
		$str .= "- " . $this->telegram->emoji(":heart-" .$team[$user->team] .":") ." L" .$user->lvl ." @" .$user->username ."\n";

		$this->telegram->send
			->text($str)
			->inline_keyboard()
				->row_button("¡Me apunto!", "raid apuntar")
			->show()
		->send();

		return -1;
	}

	elseif($this->telegram->callback == "raid apuntar"){
		$str = $this->telegram->text_message();
		$user = $pokemon->user($this->telegram->user->id);

		if(strpos($str, $user->username) !== FALSE){
			$this->telegram->answer_if_callback("¡Ya estás apuntado en la lista!", TRUE);
			return -1;
		}

		$team = ['R' => 'red', 'B' => 'blue', 'Y' => 'yellow'];
		$str .= "\n- " . $this->telegram->emoji(":heart-" .$team[$user->team] .":") ." L" .$user->lvl ." @" .$user->username;

		$this->telegram->send
			->chat(TRUE)
			->message(TRUE)
			->text($str)
			->inline_keyboard()
				->row_button("¡Me apunto!", "raid apuntar")
			->show()
		->edit('text');

		return -1;
	}
}

?>