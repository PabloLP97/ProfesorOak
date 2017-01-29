<?php

class Main extends TelegramApp\Module {
	protected $runCommands = FALSE;

	public function run(){
		$this->core->load('Tools');
		$this->core->load('Pokemon');
		// comprobar IP del host
		// if(strpos($_SERVER['REMOTE_ADDR'], "149.154.167.") === FALSE){ $this->end(); }
		// TODO log
		$this->_log(json_encode( $this->telegram->dump() ));
		// $this->_update_chat();

		if($this->user->settings['forward_interactive']){
			$this->forward_creator();
		}

		if($this->telegram->is_chat_group()){
			if($this->user->settings['forwarding_to']){ $this->forward_groups(); }
			if($this->user->settings['antiflood']){ $this->check_flood(); }
			if($this->telegram->text_url() && $this->user->settings['antispam'] !== FALSE){ $this->antispam(); }
			if($this->user->settings['die'] && $this->user->id != CREATOR){ $this->end(); }

			if($this->telegram->data_received("migrate_to_chat_id")){
				// $pokemon->group_disable($telegram->chat->id);
				// TODO mover settings
				$this->end();
			}
		}

		if($this->user->load() !== TRUE){
			// Solo puede registrarse o pedir ayuda por privado.
			$color = Tools::Color($this->telegram->text());
			if(
				($this->telegram->text_has(["Soy", "Equipo", "Team"]) && $color) or
				($color && $this->telegram->words() == 1)
			){
				$this->register($color);
			}elseif(
				$this->telegram->text_command("register") or
				($this->telegram->text_command("start") and
				!$this->telegram->is_chat_group())
			){
				$this->register(NULL);
			}elseif(
				$this->telegram->text_command("help") and
				!$this->telegram->is_chat_group()
			){
				$this->help();
			}
			$this->end();
		}

		if($this->user->blocked){ $this->end(); }

		parent::run();
	}

	function ping(){
		return $this->telegram->send
			->text("¡Pong!")
		->send();
	}

	function help(){
		$this->telegram->send
			->text('¡Aquí tienes la <a href="http://telegra.ph/Ayuda-11-30">ayuda</a>!', 'HTML')
		->send();
	}

	function register($team = NULL){
		$str = NULL;
		if($this->user->telegramid === NULL){
			if($team === NULL){
				$str = "Hola " .$this->user->telegram->first_name ."! ¿Puedes decirme qué color eres?\n"
				."<b>Di:</b> Soy...";
				if($this->telegram->is_chat_group()){
					$str = "Hola " .$this->user->telegram->first_name ."! Ábreme por privado para registrate! :)";
					$this->telegram->send
					->inline_keyboard()
						->row_button("Registrar", "https://t.me/ProfesorOak_bot")
					->show();
				}
			}elseif($team === FALSE){
				$this->telegram->send->reply_to(TRUE);
				$str = "No te he entendido bien...\n¿Puedes decirme sencillamente <b>soy rojo, soy azul</b> o <b>soy amarillo</b>?";
			}else{
				// Intentar registrar, ignorar si es anonymous.
				if($this->user->register($team) === FALSE){
					$this->telegram->send
						->text("Error general al registrar.")
					->send();
					$this->end();
				}
				if($this->user->load() !== FALSE){
					$this->user->step = "SETNAME";
					$str = "Muchas gracias " .$this->user->telegram->first_name ."! Por cierto, ¿cómo te llamas <b>en el juego</b>? \n<i>(Me llamo...)</i>";
				}
			}
		}elseif($this->user->username === NULL){
			$str = "Oye, ¿cómo te llamas? <b>Di:</b> Me llamo ...";
		}elseif($this->user->verified == FALSE){
			$str = $this->telegram->emoji(":warning:") ."¿Entiendo que quieres <b>validarte</b>?";
			$this->telegram->send
	        ->inline_keyboard()
	            ->row_button("Validar", "quiero validarme", TRUE)
	        ->show();
		}
		if(!empty($str)){
			$this->telegram->send
				->notification(FALSE)
				->text($str, 'HTML')
			->send();
		}
		$this->end();
	}

	function setname($name, $user = NULL){
		if(empty($user)){ $user = $this->user; }
		if($user->step == "SETNAME"){ $user->step = NULL; }
		try {
			$user->username = $name;
		} catch (Exception $e) {
			$this->telegram->send
				->text("Ya hay alguien que se llama @$name. Habla con @duhow para arreglarlo.")
			->send();
			$this->end();
		}
		$str = "De acuerdo, @$name!\n"
				."¡Recuerda <b>validarte</b> para poder entrar en los grupos de colores!";
		$this->telegram->send
			->text($str, 'HTML')
		->send();
		return TRUE;
	}

	function forward_creator(){
		return $this->telegram->send
			->notification(FALSE)
			->chat($this->telegram->chat->id)
			->message(TRUE)
			->forward_to(CREATOR)
		->send();
	}

	function forward_groups(){
		/* if($this->telegram->user_in_chat($this->config->item('telegram_bot_id'), $chat_forward)){ // Si el Oak está en el grupo forwarding
			$chat_accept = explode(",", $pokemon->settings($chat_forward, 'forwarding_accept'));
			if(in_array($telegram->chat->id, $chat_accept)){ // Si el chat actual se acepta como forwarding...
				$telegram->send
					->message($telegram->message)
					->chat($telegram->chat->id)
					->forward_to($chat_forward)
				->send();
			}
		} */
	}

	function check_flood(){
		if($this->user->is_admin){ return; }
		$amount = NULL;
		if($telegram->text_command()){ $amount = 1; }
		elseif($telegram->photo()){ $amount = 0.8; }
		elseif($telegram->sticker()){
			if(strpos($telegram->sticker(), "AAjbFNAAB") === FALSE){ // + BQADBAAD - Oak Games
				$amount = 1;
			}
		}
		// elseif($telegram->document()){ $amount = 1; }
		elseif($telegram->gif()){ $amount = 1; }
		elseif($telegram->text() && $telegram->words() >= 50){ $amount = 0.5; }
		elseif($telegram->text()){ $amount = -0.4; }
		// Spam de text/segundo.
		// Si se repite la última palabra.

		$countflood = 0;
		if($amount !== NULL){ $countflood = $pokemon->group_spamcount($telegram->chat->id, $amount); }

		if($countflood >= $flood){

			$ban = $pokemon->settings($telegram->chat->id, 'antiflood_ban');

			if($ban == TRUE){
				$res = $telegram->send->ban($telegram->user->id);
				if($pokemon->settings($telegram->chat->id, 'antiflood_ban_hidebutton') != TRUE){
					$telegram->send
					->inline_keyboard()
						->row_button("Desbanear", "desbanear " .$telegram->user->id, "TEXT")
					->show();
				}
			}else{
				$res = $telegram->send->kick($telegram->user->id);
			}

			if($res){
				$pokemon->group_spamcount($telegram->chat->id, -1.1); // Avoid another kick.
				$pokemon->user_delgroup($telegram->user->id, $telegram->chat->id);
				$telegram->send
					->text("Usuario expulsado por flood. [" .$telegram->user->id .(isset($telegram->user->username) ? " @" .$telegram->user->username : "") ."]")
				->send();
				$adminchat = $pokemon->settings($telegram->chat->id, 'admin_chat');
				if($adminchat){
					// TODO forward del mensaje afectado
					$telegram->send
						->chat($adminchat)
						->text("Usuario " .$telegram->user->id .(isset($telegram->user->username) ? " @" .$telegram->user->username : "") ." expulsado del grupo por flood.")
					->send();
				}
				return -1; // No realizar la acción ya que se ha explusado.
			}
			// Si tiene grupo admin asociado, avisar.
		}
	}

	function antispam(){

	}

	protected function hooks(){
		// iniciar variables
		$telegram = $this->telegram;
		// $pokemon = $this->pokemon;

		// Cancelar pasos en general.
		if($this->user->step != NULL && $telegram->text_has(["Cancelar", "Desbugear", "/cancel"], TRUE)){
			$this->user->step = NULL;
			$this->user->update();
			$telegram->send
				->notification(FALSE)
				->keyboard()->selective(FALSE)->hide()
				->text("Acción cancelada.")
			->send();
			$this->end();
		}

		$this->telegram->send->text("asdf")->send();
		if($this->telegram->text_command("register")){ return $this->register(); }
		if($this->user->step == "SETNAME" && $this->telegram->words() == 1){
			$this->setname($this->telegram->last_word(TRUE));
			$this->end();
		}
		if($this->telegram->text_command("info")){ $this->telegram->send->text($this->user->telegramid)->send(); }

		$folder = dirname(__FILE__) .'/';
		foreach(scandir($folder) as $file){
			if(is_readable($folder . $file) && substr($file, -4) == ".php"){
				$name = substr($file, 0, -4);
				if(in_array($name, ["Main", "User", "Chat"])){ continue; }
				$this->core->load($name, TRUE);
			}
		}

		$this->end();

		// Ver los IV o demás viendo stats Pokemon.
		if(
			$telegram->words() >= 4 &&
			($telegram->text_has(["tengo", "me ha salido", "calculame", "calcula iv", "calcular iv", "he conseguido", "he capturado"], TRUE) or
			$telegram->text_command("iv"))
		){
			$pk = $this->parse_pokemon();
			// TODO contar si faltan polvos o si se han especificado "caramelos" en lugar de polvos, etc.
			if(!empty($pk['pokemon'])){
				if($telegram->text_command("iv")){
					$pk["cp"] = $telegram->words(2);
					$pk["hp"] = $telegram->words(3);
					$pk["stardust"] = $telegram->words(4);
				}
				if(($pk['egg'] == TRUE) && isset($pk['distance']) && !$telegram->text_contains("calcu")){
					if(in_array($pk['distance'], [2000, 5000, 10000])){
						if(!$pokeuser->verified){ return; } // no cheaters plz
						if($pokemon->user_flags($telegram->user->id, ['troll', 'bot', 'gps'])){ return; }
						$pk['distance'] = ($pk['distance'] / 1000);
						if($pokemon->hatch_egg($pk['distance'], $pk['pokemon'], $telegram->user->id)){
							$telegram->send
								->notification(FALSE)
								->text("¡Gracias! Lo apuntaré en mi lista :)")
							->send();
						}
						return;
					}
				}elseif(isset($pk['stardust']) or isset($pk['candy'])){
					if((!isset($pk['stardust']) or empty($pk['stardust'])) and isset($pk['candy'])){
						// HACK confusión de la gente
						$pk['stardust'] = $pk['candy'];
						if(!empty($pk['hp']) and !empty($pk['cp'])){
							$telegram->send->text("¿Caramelos? Querrás decir polvos...")->send();
						}
					}
					// TODO el Pokemon sólo puede ser +1.5 del nivel de entrenador (guardado en la cuenta)
					// Calcular posibles niveles
					$levels = $pokemon->stardust($pk['stardust'], $pk['powered']);
					// $telegram->send->text(json_encode($levels))->send();

					// Si tiene HP y CP puesto, calvular IV
					if(isset($pk['hp']) and isset($pk['cp'])){
						$chat = ($telegram->is_chat_group() && $this->is_shutup(TRUE) ? $telegram->user->id : $telegram->chat->id);
						$pokedex = $pokemon->pokedex($pk['pokemon']);
						$this->analytics->event("Telegram", "Calculate IV", $pokedex->name);
						// De los niveles que tiene...
						$table = array();
						$low = 100;
						$high = 0; // HACK invertidas
						foreach($levels as $lvl){
							$lvlmp = $pokemon->level($lvl)->multiplier;
							$pow = pow($lvlmp, 2) * 0.1;
							for($IV_STA = 0; $IV_STA < 16; $IV_STA++){
								$hp = max(floor(($pokedex->stamina + $IV_STA) * $lvlmp), 10);
								// Si tenemos el IV de HP y coincide con su vida...
								if($hp == $pk['hp']){
									$lvl_STA = sqrt($pokedex->stamina + $IV_STA) * $pow;
									$cps = array(); // DEBUG
									for($IV_DEF = 0; $IV_DEF < 16; $IV_DEF++){
			                            for($IV_ATK = 0; $IV_ATK < 16; $IV_ATK++){
											$cp = floor( ($pokedex->attack + $IV_ATK) * sqrt($pokedex->defense + $IV_DEF) * $lvl_STA);
											// Si el CP calculado coincide con el nuestro, agregar posibilidad.
											if($cp == $pk['cp']){
												$sum = (($IV_ATK + $IV_DEF + $IV_STA) / 45) * 100;
												if($sum > $high){ $high = $sum; }
												if($sum < $low){ $low = $sum; }
												$table[] = ['level' => $lvl, 'atk' => $IV_ATK, 'def' => $IV_DEF, 'sta' => $IV_STA];
											}
											$cps[] = $cp; // DEBUG
										}
									}
									if($this->user->id == $this->config->item('creator')){
										// $telegram->send->text(json_encode($cps))->send(); // DEBUG
									}
								}
							}
						}
						if(count($table) > 1 and ($pk['attack'] or $pk['defense'] or $pk['stamina'])){
							// si tiene ATK, DEF O STA, los resultados
							// que lo superen, quedan descartados.
							foreach($table as $i => $r){
								if($pk['attack'] and ( max($r['atk'], $r['def'], $r['sta']) != $r['atk'] )){ unset($table[$i]); continue; }
								if($pk['defense'] and ( max($r['atk'], $r['def'], $r['sta']) != $r['def'] )){ unset($table[$i]); continue; }
								if($pk['stamina'] and ( max($r['atk'], $r['def'], $r['sta']) != $r['sta'] )){ unset($table[$i]); continue; }
								if($pk['attack'] and isset($pk['ivcalc']) and !in_array($r['atk'], $pk['ivcalc'])){ unset($table[$i]); continue; }
								if($pk['defense'] and isset($pk['ivcalc']) and !in_array($r['def'], $pk['ivcalc'])){ unset($table[$i]); continue; }
								if($pk['stamina'] and isset($pk['ivcalc']) and !in_array($r['sta'], $pk['ivcalc'])){ unset($table[$i]); continue; }
								if((!$pk['attack'] or !$pk['defense'] or !$pk['stamina']) and ($r['atk'] + $r['def'] + $r['sta'] == 45)){ unset($table[$i]); continue; }
							}
							$low = 100;
							$high = 0;
							foreach($table as $r){
								$sum = (($r['atk'] + $r['def'] + $r['sta']) / 45) * 100;
								if($sum > $high){ $high = $sum; }
								if($sum < $low){ $low = $sum; }
							}
						}

						$frases = [
							'Es una.... mierda. Si quieres caramelos, ya sabes que hacer.',
							'Bueno, no está mal. :)',
							'Oye, ¡pues mola!',
							'Menuda suerte que tienes, cabrón...'
						];

						if(count($table) == 0){
							$text = "Los cálculos no me salen...\n¿Seguro que me has dicho bien los datos?";
						}elseif(count($table) == 1){
							if($low == $high){ $sum = round($high, 1); }
							reset($table); // HACK Reiniciar posicion
							$r = current($table); // HACK Seleccionar primer resultado
							$frase = 0;
							if($sum <= 50){ $frase = 0; }
							elseif($sum > 50 && $sum <= 66){ $frase = 1; }
							elseif($sum > 66 && $sum <= 80){ $frase = 2; }
							elseif($sum > 80){ $frase = 3; }
							$text = "Pues parece que tienes un *$sum%*!\n"
									.$frases[$frase] ."\n"
									."*L" .round($r['level']) ."* " .$r['atk'] ." ATK, " .$r['def'] ." DEF, " .$r['sta'] ." STA";
						}else{
							$low = round($low, 1);
							$high = round($high, 1);
							$text = "He encontrado *" .count($table) ."* posibilidades, "; // \n
							if($low == $high){ $text .= "con un *$high%*."; }
							else{ $text .= "entre *" .round($low, 1) ."% - " .round($high, 1) ."%*."; }

							if($high <= 50 or ($low <= 60 and $high <= 60) ){ $frase = 0; }
							elseif($low > 75){ $frase = 3; }
							elseif($low > 66){ $frase = 2; }
							elseif($low > 50 or ($high >= 75 and $low <= 65)){ $frase = 1; }

							$text .= "\n" .$frases[$frase] ."\n";

							// Si hay menos de 6 resultados, mostrar.
							if(count($table) <= 6){
								$text .= "\n";
								foreach($table as $r){
									$total = number_format(round((($r['atk'] + $r['def'] + $r['sta']) / 45) * 100, 1), 1);
									$text .= "*L" .$r['level'] ."* - *" .$total ."%*: " .$r['atk'] ."/" .$r['def'] ."/" .$r['sta'] ."\n";
								}
							}
						}

						$telegram->send->chat($chat)->text($text, TRUE)->send();
						if($this->user->id == $this->config->item('creator') && !$telegram->is_chat_group()){
							// $telegram->send->text(json_encode($table))->send(); // DEBUG
						}
					}
				}
				return;
			}
		}

		// PARTE 2


		// PARTE 3

		if($telegram->text_contains( ["atacando", "atacan"]) && $telegram->text_contains(["gimnasio", "gym"])){

		}elseif($telegram->text_has(["evolución", "evolucionar"])){
			$chat = ($telegram->text_has("aquí") && !$this->is_shutup() ? $telegram->chat->id : $telegram->user->id);

			$pk = $this->parse_pokemon();
			if(empty($pk['pokemon'])){ return; }

			$search = $pokemon->pokedex($pk['pokemon']);
			$this->analytics->event('Telegram', 'Search Pokemon Evolution', $search->name);

			$evol = $pokemon->evolution($search->id);
			$str = array();
			if(count($evol) == 1){ $str = "No tiene."; }
			else{
				foreach($evol as $i => $p){
					$cur = FALSE;
					if($p['id'] == $search->id){ $cur = TRUE; }

					$frase = ($cur ? $telegram->emoji(":triangle-right:") ." *" .$p['name'] ."*" : $p['name']);
					$frase .= ($p['candy'] != NULL && $p['candy'] > 0 ? " (" .$p['candy'] .$telegram->emoji(" :candy:") .")" : "");

					if(!empty($pk['cp'])){
						if(!$cur && !empty($p['evolved_from'])){ $pk['cp'] = min(floor($pk['cp'] * $p['evolved_from']['cp_multi']), $p['cp_max']); }
						if($cur or !empty($p['evolved_from'])){ $frase .= " *" .$pk['cp'] ." CP*"; }
					}
					$str[] = $frase;
				}
				$str = implode("\n", $str);

			}
			$telegram->send
				->chat( $chat )
				->notification(FALSE)
				// ->reply_to( ($chat == $telegram->chat->id) )
				->text($str, TRUE)
			->send();
		}elseif(($telegram->text_has(["ataque", "habilidad", "skill"], TRUE) or $telegram->text_command("attack")) && $telegram->words() <= 5){
			$chat = ($telegram->text_has("aquí") && !$this->is_shutup() ? $telegram->chat->id : $telegram->user->id);

			$find = $telegram->words(1, 2);
			if($telegram->text_has("aquí")){
				$find = $telegram->words(1, $telegram->words() - 2);
			}
			$skill = $pokemon->skill($find);
			if($skill){
				$types = $pokemon->attack_types();
				$text = "*" .$skill->name_es ."* / _" .$skill->name ."_\n"
						.$types[$skill->type] ." - " .$skill->attack ." ATK / " .$skill->bars ." barras";

				$telegram->send
					->notification(TRUE)
					->chat($chat)
					->text($text, TRUE)
				->send();
				return;
			}
		}elseif($telegram->text_has(["pokédex", "pokémon"], TRUE) or $telegram->text_command("pokedex")){
			// $text = $telegram->text();
			// $chat = ($telegram->text_has("aqui") && !$this->is_shutup() ? $telegram->chat->id : $telegram->user->id);
			/* if($telegram->text_has("aquí")){
				$word = $telegram->words( $telegram->words() - 2 );
			} */
			$this->_pokedex($telegram->text(), $telegram->chat->id);

		// ---------------------
		// Utilidades varias
		// ---------------------


		// ---------------------
		// Administrativo
		// ---------------------

		}elseif($telegram->text_has(["team", "equipo"]) && $telegram->text_has(["sóis", "hay aquí", "estáis"])){
			exit();
		}elseif($telegram->text_has(["pokemon", "pokemons", "busca", "buscar", "buscame"]) && $telegram->text_contains("cerca") && $telegram->words() <= 10){
			$this->_locate_pokemon();
			return;
		}
		// ---------------------
		// Chistes y tonterías
		// ---------------------

		// Recibir ubicación
		if($telegram->location() && !$telegram->is_chat_group()){
		    $loc = implode(",", $telegram->location(FALSE));
		    $pokemon->settings($telegram->user->id, 'location', $loc);
		    $pokemon->step($telegram->user->id, 'LOCATION');
		    $this->_step();
		}

		/* if($telegram->text_has(["agregar"], TRUE) && $telegram->words() == 3 && $telegram->has_reply && isset($telegram->reply->location)){
			$loc = (object) $telegram->reply->location;
			$loc = [$loc->latitude, $loc->longitude];

			$am = $telegram->words(1);
			$dir = $telegram->words(2);
			if(!is_numeric($am)){ exit(); }

			$telegram->send
				->text($pokemon->location_add($loc, $am, $dir))
			->send();
			exit();
		} */

		// NUEVO MOLESTO
		if($telegram->photo() && $telegram->user->id != $this->config->item('creator')){
			if($pokeuser->verified){ return; }
			$pokemon->step($telegram->user->id, 'SCREENSHOT_VERIFY');
			$this->_step();
		}
	}

	function _step(){
		$telegram = $this->telegram;
		$pokemon = $this->pokemon;
		$user = $telegram->user;
		$chat = $telegram->chat;

		$pokeuser = $pokemon->user($this->user->id);
		if(empty($pokeuser)){ return; } // HACK cuidado

		$admins = NULL;
		if($telegram->is_chat_group()){ $admins = $telegram->get_admins(); }
		$admins[] = $this->config->item('creator');

		$step = $pokeuser->step;
		switch ($step) {
			case 'RULES':
				if(!$telegram->is_chat_group()){ break; }
				if(!in_array($this->user->id, $admins)){ $pokemon->step($this->user->id, NULL); break; }

				$text = $telegram->text_encoded();
				if(strlen($text) < 4){ exit(); }
				if(strlen($text) > 4000){
					$telegram->send
						->text("Buah, demasiadas normas. Relájate un poco anda ;)")
					->send();
					exit();
				}
				$this->analytics->event('Telegram', 'Set rules');
				$pokemon->settings($telegram->chat->id, 'rules', $text);
				$telegram->send
					->text("Hecho!")
				->send();
				$pokemon->step($this->user->id, NULL);
				break;
			case 'WELCOME':
				if(!$telegram->is_chat_group()){ break; }
				if(!in_array($this->user->id, $admins)){ $pokemon->step($this->user->id, NULL); break; }

				$text = $telegram->text_encoded();
				if(strlen($text) < 4){ exit(); }
				if(strlen($text) > 4000){
					$telegram->send
						->text("Buah, demasiado texto! Relájate un poco anda ;)")
					->send();
					exit();
				}
				$this->analytics->event('Telegram', 'Set welcome');
				$pokemon->settings($telegram->chat->id, 'welcome', $text);
				$telegram->send
					->text("Hecho!")
				->send();
				$pokemon->step($this->user->id, NULL);
				break;
			case 'CHOOSE_POKEMON':
				// $pk = NULL;
				$pk = $this->parse_pokemon();
				$pokemon->step($this->user->id, 'CHOOSE_POKEMON');
				/* if($telegram->text()){
					$pk = trim($telegram->words(0, TRUE));
					// if( preg_match('/^(#?)\d{1,3}$/', $word) ){ }
				}elseif($telegram->sticker()){
					// Decode de la lista de stickers cuál es el Pokemon.
				} */
				if(!empty($pk)){
					// $pk = $pokemon->find($pk);
					if(empty($pk['pokemon'])){
						$telegram->send
							->text("El Pokémon mencionado no existe.")
						->send();
					}else{
						$s = $pokemon->settings($this->user->id, 'step_action');
						$pokemon->step($this->user->id, $s);
						$pokemon->settings($this->user->id, 'pokemon_select', $pk['pokemon']);
						$this->_step(); // HACK relaunch
					}
				}
				exit();
				break;
			case 'POKEMON_SEEN':
				// Tienes que estar en el lugar para poder haber reportado el Pokemon
				// Si tienes flags TROLL, FC u otras, no podrás enviarlo.
				// Solo puedes hacer uno cada minuto.
				$pk = $pokemon->settings($this->user->id, 'pokemon_select');

				$pokemon->settings($this->user->id, 'pokemon_select', 'DELETE');
				$pokemon->settings($this->user->id, 'step_action', 'DELETE');

				$cd = $pokemon->settings($this->user->id, 'pokemon_cooldown');
				if(!empty($cd) && $cd > time()){
					$telegram->send->text("Aún no ha pasado suficiente tiempo. Espera un poco, anda. :)");
					$pokemon->step($this->user->id, NULL);
					exit();
				}

				if($pokemon->user_flags($this->user->id, ['troll', 'rager', 'bot', 'forocoches', 'hacks', 'gps', 'trollmap'])){
					$telegram->send->text("nope.")->send();
					$pokemon->step($this->user->id, NULL);
					exit();
				}
				$loc = explode(",", $pokemon->settings($this->user->id, 'location')); // FIXME cuidado con esto, si reusamos la funcion.
				$pokemon->add_found($pk, $this->user->id, $loc[0], $loc[1]);

				// SELECT uid, SUBSTRING(value, 1, INSTR(value, ",") - 1) AS lat, SUBSTRING(value, INSTR(value, ",") + 1) AS lng FROM `settings` WHERE LEFT(uid, 1) = '-' AND type = "location"

				$pokemon->settings($this->user->id, 'pokemon_cooldown', time() + 60);
				$pokemon->step($this->user->id, NULL);

				$this->analytics->event("Telegram", "Pokemon Seen", $pk);
				$telegram->send
					->text("Hecho! Gracias por avisar! :D")
					->keyboard()->hide(TRUE)
				->send();
				exit();
				break;
			case 'LURE_SEEN':
				// Tienes que estar en el lugar para poder haber reportado el Pokemon
				// Si tienes flags TROLL, FC u otras, no podrás enviarlo.
				// Solo puedes hacer uno cada minuto.
				$pokemon->settings($this->user->id, 'step_action', 'DELETE');

				$cd = $pokemon->settings($this->user->id, 'pokemon_cooldown');
				if(!empty($cd) && $cd > time()){
					$telegram->send->text("Aún no ha pasado suficiente tiempo. Espera un poco, anda. :)");
					$pokemon->step($this->user->id, NULL);
					exit();
				}

				if($pokemon->user_flags($this->user->id, ['troll', 'rager', 'bot', 'forocoches', 'hacks', 'gps', 'trollmap'])){
					$telegram->send->text("nope.")->send();
					$pokemon->step($this->user->id, NULL);
					exit();
				}
				$loc = explode(",", $pokemon->settings($this->user->id, 'location')); // FIXME cuidado con esto, si reusamos la funcion.

				// Buscar Pokeparada correspondiente o cercana.
				$pkstop = $pokemon->pokestops($loc, 160, 1);
				if(!$pkstop){
					$telegram->send
						->text("No hay Pokeparadas por ahí cerca, o no están registradas. Pregúntalo más adelante.")
					->send();
					$telegram->send
						->chat($this->config->item('creator'))
						->text("*!!* Buscar Poképaradas en *" .json_encode($loc) ."*", TRUE)
					->send();
					return;
				}
				$pkstop = $pkstop[0];
				$loc = [$pkstop['lat'], $pkstop['lng']];

				$pokemon->add_lure_found($this->user->id, $loc[0], $loc[1]);

				$nearest = $pokemon->group_near($loc);
				foreach($nearest as $g){
					$telegram->send
						->chat($g)
						->location($loc)
					->send();

					$text = "Cebo en *" .$pkstop['title'] ."*!";
					$telegram->send
						->chat($g)
						->text($text, TRUE)
					->send();
				}
				// SELECT uid, SUBSTRING(value, 1, INSTR(value, ",") - 1) AS lat, SUBSTRING(value, INSTR(value, ",") + 1) AS lng FROM `settings` WHERE LEFT(uid, 1) = '-' AND type = "location"

				$pokemon->settings($this->user->id, 'pokemon_cooldown', time() + 60);
				$pokemon->step($this->user->id, NULL);

				$this->analytics->event("Telegram", "Lure Seen", $pk);
				$telegram->send
					->text("Cebo en *" .$pkstop['title'] ."*, gracias por avisar! :D", TRUE)
					->keyboard()->hide(TRUE)
				->send();
				exit();
				break;
			case 'MEETING_LOCATION';

				break;
			case 'SCREENSHOT_VERIFY':
				if(!$telegram->is_chat_group() && $telegram->photo()){
					if(empty($pokeuser->username) or $pokeuser->lvl == 1){
						$text = "Antes de validarte, necesito saber tu *nombre o nivel actual*.\n"
								.":triangle-right: *Me llamo ...*\n"
								.":triangle-right: *Soy nivel ...*";
						$telegram->send
							->notification(TRUE)
							->chat($telegram->user->id)
							->text($telegram->emoji($text), TRUE)
							->keyboard()->hide(TRUE)
						->send();
						exit();
					}

					$telegram->send
						->message(TRUE)
						->chat(TRUE)
						->forward_to($this->config->item('creator'))
					->send();

					$telegram->send
						->notification(TRUE)
						->chat($this->config->item('creator'))
						->text("Validar " .$this->user->id ." @" .$pokeuser->username ." L" .$pokeuser->lvl ." " .$pokeuser->team)
						->inline_keyboard()
							->row()
								->button($telegram->emoji(":ok:"), "te valido " .$pokeuser->telegramid, "TEXT")
								->button($telegram->emoji(":times:"), "no te valido")
							->end_row()
						->show()
					->send();

					$telegram->send
						->notification(TRUE)
						->chat($this->user->id)
						->keyboard()->hide(TRUE)
						->text("¡Enviado correctamente! El proceso de validar puede tardar un tiempo.")
					->send();

					$pokemon->step($this->user->id, NULL);
					exit();
				}
				break;
			case 'SPEAK':
				// DEBUG - FIXME
				if($telegram->is_chat_group()){ return; }
				if($telegram->callback){ return; }
				if($telegram->text() && substr($telegram->words(0), 0, 1) == "/"){ return; }
				$chattalk = $pokemon->settings($telegram->user->id, 'speak');
				if($telegram->user->id != $this->config->item('creator') or $chattalk == NULL){
					$pokemon->step($telegram->user->id, NULL);
				}
				$telegram->send
					->notification(TRUE)
					->chat($chattalk);

				if($telegram->text()){
					$telegram->send->text( $telegram->text(), 'Markdown' )->send();
				}elseif($telegram->photo()){
					$telegram->send->file('photo', $telegram->photo());
				}elseif($telegram->sticker()){
					$telegram->send->file('sticker', $telegram->sticker());
				}elseif($telegram->voice()){
					$telegram->send->file('voice', $telegram->voice());
				}elseif($telegram->video()){
					$telegram->send->file('video', $telegram->video());
				}
				exit();
				break;
			case 'DUMP':
				$telegram->send->text( $telegram->dump(TRUE) )->send();
				exit();
				break;
			case 'SETNAME':
				// Last word con filtro de escapes.

				break;
			default:
			break;
		}
		// exit(); // FIXME molesta. se queda comentado.
	}

	// function _pokedex($chat = NULL){
	function _pokedex($text = NULL, $chat = NULL){
		$telegram = $this->telegram;
		$pokemon = $this->pokemon;

		$this->last_command("POKEDEX");

		$types = $pokemon->attack_types();

		if($chat === NULL){ $chat = $telegram->chat->id; }
		if(!is_numeric($text)){
			$exp = explode(" ", $text);
			if(in_array(count($exp), [2, 3])){ // el aquí también cuenta
				$num = filter_var($exp[1], FILTER_SANITIZE_NUMBER_INT);
				if(is_numeric($num) && $num > 0 && $num < 251){ $text = $num; }
			}
			if(!is_numeric($text)){
				$poke = $this->parse_pokemon();
				$text = (!empty($poke['pokemon']) ? $poke['pokemon'] : NULL);
			}
		}

		if(empty($text)){ return; }
		$pokedex = $pokemon->pokedex($text);
		$str = "";
		if(!empty($pokedex)){
			$skills = $pokemon->skill_learn($pokedex->id);

			$str = "*#" .$pokedex->id ."* - " .$pokedex->name ."\n"
					.$types[$pokedex->type] .($pokedex->type2 ? " / " .$types[$pokedex->type2] : "") ."\n"
					."ATK " .$pokedex->attack ." - DEF " .$pokedex->defense ." - STA " .$pokedex->stamina ."\n\n";

			foreach($skills as $sk){
				$str .= "[" .$sk->attack ."/" .$sk->bars ."] - " .$sk->name_es  ."\n";
			}
		}

		if($pokedex->sticker && ($chat == $telegram->user->id)){
			$telegram->send
				->chat($chat)
				// ->notification(FALSE)
				->file('sticker', $pokedex->sticker);
		}
		if(!empty($str)){
			$telegram->send
				->chat($chat)
				// ->notification(FALSE)
				->text($str, TRUE)
			->send();
		}
	}

	function _locate_pokemon(){
		$telegram = $this->telegram;
		$pokemon = $this->pokemon;

		$distance = 500;
		$limit = 10;

		$this->last_command("POKEMAP");

		// Bloquear a trols y otros.
		if($pokemon->user_flags($telegram->user->id, ['troll', 'rager', 'spam', 'bot', 'gps', 'hacks'])){ return; }
		// Comprobar cooldown.
		if($pokemon->settings($telegram->user->id, 'pokemap_cooldown') > time()){ return; }
		// Desactivar por grupos
		if($pokemon->settings($telegram->chat->id, 'location_disable') && $telegram->user->id != $this->config->item('creator')){ return; }

		// Parsear datos Pokemon
		$pk = $this->parse_pokemon();

		if(isset($pk['distance'])){ $distance = $pk['distance']; }
		if($telegram->is_chat_group() && $pokemon->settings($telegram->chat->id, 'location')){
			// GET location del grupo
			$loc = explode(",", $pokemon->settings($telegram->chat->id, 'location'));
			$dist = $pokemon->settings($telegram->chat->id, 'location_radius');
			// Radio por defecto 5km.
			$distance = (is_numeric($dist) ? $dist : 5000);
		}else{
			// GET location
			$loc = explode(",", $pokemon->settings($telegram->user->id, 'location'));
		}
		// die();
		$list = $pokemon->spawn_near($loc, $distance, $limit, $pk['pokemon']);
		$str = "No se han encontrado Pokemon.";
		if($telegram->user->id == $this->config->item('creator')){
			$telegram->send->text("Calculando especial...")->send();
			if(!function_exists("pokeradar")){
				$telegram->send->text("Función especial no cargada. Mu mal David. u.u")->send();
				return;
			}
			$list = pokeradar($loc, $distance, $limit, $pk['pokemon']);
			$telegram->send->chat($this->config->item('creator'))->text($list)->send();
			return;
			// $list = $pokemon->pokecrew($loc, $distance, $limit, $pk['pokemon']);
		}
		if(!empty($list)){
			$str = "";
			$pokedex = $pokemon->pokedex();
			$pkfind = (empty($pk['pokemon']) ? "All" : $pokedex[$pk['pokemon']]->name);
			$this->analytics->event("Telegram", "Search Pokemon Location", $pkfind);
			if(count($list) > 1){
				foreach($list as $e){
					$met = floor($e['distance']);
					if($met > 1000){ $met = round($met / 1000, 2) ."km"; }
					else{ $met .= "m"; }

					$str .= "*" .$pokedex[$e['pokemon']]->name ."* en $met" ." (" .date("d/m H:i", strtotime($e['last_seen'])) .")" ."\n";
				}
			}else{
				$e = $list[0]; // Seleccionar el primero
				$met = floor($e['distance']);
				if($met > 1000){ $met = round($met / 1000, 2) ."km"; }
				else{ $met .= "m"; }

				$str = "Tienes a *" .$pokedex[$e['pokemon']]->name ."* a $met, ve a por él!\n"
						."(" .date("d/m H:i", strtotime($e['last_seen'])) .")";
				$telegram->send->location($e['lat'], $e['lng'])->send();
			}
		}
		$time = (empty($list) ? 10 : 15); // Cooldown en función de resultado
		$pokemon->settings($telegram->user->id, 'pokemap_cooldown', time() + $time);
		$telegram->send->keyboard()->hide()->text($str, TRUE)->send();
	}

	function last_command($action){
		$user = $this->telegram->user->id;
		$chat = $this->telegram->chat->id;
		$pokemon = $this->pokemon;

		$command = $pokemon->settings($user, 'last_command');
		$amount = 1;
		if($command == $action){
			$count = $pokemon->settings($user, 'last_command_count');
			$add = ($user == $chat ? 0 : 1); // Solo agrega si es grupo
			$amount = (empty($count) ? 1 : ($count + $add));
		}
		$pokemon->settings($user, 'last_command', $action);
		$pokemon->settings($user, 'last_command_count', $amount);
	}

	function is_shutup($creator = TRUE){
		$admins = $this->admins($creator);
		$shutup = $this->pokemon->settings($this->telegram->chat->id, 'shutup');
		return ($shutup && !in_array($this->telegram->user->id, $admins));
		// $this->telegram->user->id != $this->config->item('creator')
	}

	function is_shutup_jokes(){
		$can = $this->pokemon->settings($this->telegram->chat->id, 'jokes');
		return ($this->is_shutup() or ($can != NULL && $can == FALSE));
	}

	function admins($add_creator = TRUE, $custom = NULL){
		$admins = $this->pokemon->group_admins($this->telegram->chat->id);
		if(empty($admins)){
			$admins = $this->telegram->get_admins(); // Del grupo
			$this->pokemon->group_admins($this->telegram->chat->id, $admins);
		}
		if($add_creator){ $admins[] = $this->config->item('creator'); }
		if($custom != NULL){
			if(!is_array($custom)){ $custom = [$custom]; }
			foreach($custom as $c){ $admins[] = $c; }
		}
		return $admins;
	}

	function _log($texto){
		$fp = fopen('error.loga', 'a');
		fwrite($fp, $texto ."\n");
		fclose($fp);
	}

	function _update_chat(){
		$chat = $this->telegram->chat;
		$user = $this->telegram->user;

		if(empty($chat->id)){ return; }
		$query = $this->db
			->where('id', $chat->id)
		->get('chats');
		if($query->num_rows() == 1){
			// UPDATE
			$this->db
				->set('type', $chat->type)
				->set('title', @$chat->title)
				->set('last_date', date("Y-m-d H:i:s"))
				->set('active', TRUE)
				->set('messages', 'messages + 1', FALSE)
				->where('id', $chat->id)
			->update('chats');
		}else{
			$this->db
				->set('id', $chat->id)
				->set('type', $chat->type)
				->set('title', $chat->title)
				->set('active', TRUE)
				->set('register_date', date("Y-m-d H:i:s"))
			->insert('chats');
		}

		$query = $this->db
			->where('uid', $this->user->id)
			->where('cid', $chat->id)
		->get('user_inchat');
		if($query->num_rows() == 1){
			// UPDATE
			$this->db
				->where('uid', $this->user->id)
				->where('cid', $chat->id)
				->set('messages', 'messages + 1', FALSE)
				->set('last_date', date("Y-m-d H:i:s"))
			->update('user_inchat');
		}

		if($this->pokemon->user_exists($this->telegram->user->id)){
			if(isset($this->telegram->user->username) && !empty($this->telegram->user->username)){
				$this->pokemon->update_user_data($this->telegram->user->id, 'telegramuser', $this->telegram->user->username);
			}
		}
	}
}
