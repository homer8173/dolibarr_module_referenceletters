<?php

class RfltrTools {

	static function setImgLinkToUrl($txt) {

		return strtr($txt, array('src="'.dol_buildpath('viewimage.php', 1) => 'src="'.dol_buildpath('viewimage.php', 2), '&amp;'=>'&'));

	}

	static function setImgLinkToUrlWithArray($Tab) {

		foreach($Tab as $id_chapter=>&$TData) {
			$TData['content_text'] = self::setImgLinkToUrl($TData['content_text']);
		}
		return $Tab;
	}

	/**
	 * @param $obj peut être une convetion pour Agefodd ou une propal, une cmd, etc ...
	 * charge le modèle référence letter choisi
	 */
	static function load_object_refletter($id_object, $id_model, &$object, $socid='', $lang_id='') {

		global $db, $conf, $langs;

		dol_include_once('/referenceletters/class/referenceletters.class.php');
		dol_include_once('/referenceletters/class/referenceletterselements.class.php');
		dol_include_once('/referenceletters/class/referenceletterschapters.class.php');

		$object_refletter = new Referenceletters($db);
		$object_refletter->fetch($id_model);

		if(empty($object->thirdparty) && is_callable(array($object, 'fetch_thirdparty'))) $object->fetch_thirdparty();

        if(is_object($object) && get_class($object) === 'Contrat') {
                $lines = $object->getLinesArray();
                if (!empty($lines))
                {
					$object->lines_active = array();

                    foreach ($lines as $line)
                    {
                        if ($line->statut == 4) $object->lines_active[] = $line;
                    }
                }
        }

    	if (! empty($lang_id)) {
			$outputlangs = new Translate("", $conf);
			$outputlangs->setDefaultLang($lang_id);
			$outputlangs->load('main');
			$outputlangs->load('agefodd@agefodd');
		} else {
			global $langs;
			$outputlangs=$langs;
		}

		if(is_object($object) && (get_class($object) === 'Facture' || get_class($object) === 'Commande' || get_class($object) === 'Propal' || get_class($object) === 'Contrat'|| get_class($object) === 'Societe' || get_class($object) === 'Contact' || get_class($object) === 'SupplierProposal' || get_class($object) === 'CommandeFournisseur'|| get_class($object) === 'FactureFournisseur'))  {
			if(empty($object->thirdparty)) {
				$object->fetch_thirdparty();
			}
		}
		else{
			$objectLoaded = self::load_agefodd_object($id_object, $object_refletter, $socid, $object, $outputlangs);
			unset($object); // suppression du lien avec le paramettre $object de cette methode sinon cela change aussi l'objet d'origine
			$object = $objectLoaded;
		}

		if (!empty($lang_id)) $langs_chapter = $outputlangs->defaultlang;
		else {
			if (empty($langs_chapter) && ! empty($conf->global->MAIN_MULTILANGS)) $langs_chapter = $object->thirdparty->default_lang;
			if (empty($langs_chapter)) $langs_chapter = $langs->defaultlang;
		}

		$object_chapters = new ReferencelettersChapters($db);
		$result = $object_chapters->fetch_byrefltr($id_model, $langs_chapter);

		$content_letter = array();
		if (is_array($object_chapters->lines_chapters) && count($object_chapters->lines_chapters) > 0) {

			foreach ( $object_chapters->lines_chapters as $key => $line_chapter ) {

				$options = array();
				if (is_array($line_chapter->options_text) && count($line_chapter->options_text) > 0) {
					foreach ( $line_chapter->options_text as $key => $option_text ) {
						$options[$key] = array (
								'use_content_option' => GETPOST('use_content_option_' . $line_chapter->id . '_' . $key),
								'text_content_option' => GETPOST('text_content_option_' . $line_chapter->id . '_' . $key)
						);
					}
				}

				$content_letter[$line_chapter->id] = array (
						'content_text' => $line_chapter->content_text,
						'options' => $options,
						'same_page' => $line_chapter->same_page
				);
			}
		}

		// On load le modèle
		$instance_letter = new ReferenceLettersElements($db);
		$instance_letter->fetch($id_model);
		$instance_letter->srcobject=$object;
		$instance_letter->content_letter = self::setImgLinkToUrlWithArray($content_letter);
		if(is_object($object) && empty($object->thirdparty)) $object->fetch_thirdparty();
		$element_type='rfltr_agefodd_convention';
		//$instance_letter->ref_int = $instance_letter->getNextNumRef($object->thirdparty, $user->id, $element_type); // TODo pour l'instant on garde le même nom de pdf que fait agefodd
		$instance_letter->title = $object_refletter->title;
		$instance_letter->fk_element = $object->id;
		$instance_letter->element_type = $object_refletter->element_type;
		$instance_letter->fk_referenceletters = $id_model;
		$instance_letter->outputref = '';
		$instance_letter->use_custom_header = $object_refletter->use_custom_header;
		$instance_letter->use_custom_footer = $object_refletter->use_custom_footer;
		$instance_letter->header = self::setImgLinkToUrl($object_refletter->header);
		$instance_letter->footer = self::setImgLinkToUrl($object_refletter->footer);
		$instance_letter->use_landscape_format= $object_refletter->use_landscape_format;
		$instance_letter->title_referenceletters = $object_refletter->title;

		return array($instance_letter, $object);

	}

	/**
	 * Charge l'objet Agefodd session ainsi que toutes les données associées (liste des participants, horaires)
	 */
	static function load_agefodd_object($id_object, &$object_refletter, $socid='', $obj_agefodd_convention='', $outputlangs='') {

		global $db;

		dol_include_once('/agefodd/class/agsession.class.php');
		$object = new $object_refletter->element_type_list['rfltr_agefodd_convention']['objectclass']($db);
		$object->fetch($id_object);
		$object->load_all_data_agefodd_session($object_refletter, $socid, $obj_agefodd_convention, false, $outputlangs);

		return $object;

	}

	static function getAgefoddModelList() {

		global $db;

		$sql = 'SELECT rowid, title, element_type , default_doc
				FROM '.MAIN_DB_PREFIX.'referenceletters
				WHERE element_type LIKE "%agefodd%"
				AND entity IN (' . getEntity('referenceletters') . ")
				AND status=1";

		$resql = $db->query($sql);
		if(!empty($resql)) {

			$TModels=array();
			while($res = $db->fetch_object($resql)) {

				$TModels[$res->element_type][$res->rowid]=$res->title;

			}
			return $TModels;
		} else return 0;

	}

	static function getAgefoddModelListDefault() {

		global $db;
		$sql = 'SELECT rowid, title, element_type , default_doc
				FROM '.MAIN_DB_PREFIX.'referenceletters
				WHERE element_type LIKE "%agefodd%"
				AND entity IN (' . getEntity('referenceletters') . ")
				AND status=1";

		$resql = $db->query($sql);
		if(!empty($resql)) {

			$TModels=array();
			while($res = $db->fetch_object($resql)) {

				$TModels[]=$res;

			}
			return $TModels;
		} else return 0;

	}

	static function getAgefoddModelListDefaultJSON() {
		$TDefaultModel=array();
		$TModel = self::getAgefoddModelListDefault();
		if (is_array($TModel) && count($TModel)>0) {
			foreach($TModel as $line) {
				if (!empty($line->default_doc) && !array_key_exists($line->element_type, $TDefaultModel) && $line->element_type!=='rfltr_agefodd_convention')  {
					$TDefaultModel[str_replace('rfltr_agefodd_', '', $line->element_type)]=$line->rowid;
				}
			}
		}
		return json_encode($TDefaultModel);


	}

	static function print_js_external_models($page='document') {
		?>

		<script type="text/javascript">

			$(document).ready(function() {

				var defaultdoc=JSON.parse('<?php print self::getAgefoddModelListDefaultJSON();?>');
				console.log(defaultdoc);
				$("a[name^='builddoc_']").each(function () {
					if (defaultdoc[$(this).attr("name").split("__")[1]]) {
						var _href = $(this).attr("href");
						$(this).attr("href", _href + '&id_external_model='+defaultdoc[$(this).attr("name").split("__")[1]]);
					}

				});

				// Affichage de la liste des modèles disponibles
				$(".btn_show_external_model_list").click(function() {

					var class_to_show = '.' + $(this).attr('class_to_show');
					var val_link = $(this).text();

					if(val_link == '+') {
						// $(class_to_show).show();
						$(this).parent().find('#id_external_model').show();
						$(this).html('-');
					} else if(val_link == '-') {
						// $(class_to_show).hide();
						$(this).parent().find('#id_external_model').hide();
						$(this).html('+');
					}

				});
				// Sélection du modèle et génération du document
				$(".id_external_model").change(function() {

					var path = '<?php echo $_SERVER['PHP_SELF']; ?>' + '?id=' + <?php echo GETPOST('id'); ?> + '&model=' + $(this).attr('model') + '&action=create&id_external_model=' + $(this).val();
					// On récupère l'attribut name du lien présent dans la première ligne liste_titre avant celle sur laquelle on se trouve
					lignetitre = $(this).parent().parent();
					while(!lignetitre.hasClass('liste_titre')) {
						lignetitre = lignetitre.prev();
					}
					var sessiontrainerid = lignetitre.find('a').attr('name');
					<?php

						if($page === 'document') {
							?>
								if(typeof sessiontrainerid != 'undefined' && sessiontrainerid == 'trainerid'+$(this).attr('socid')) {
									path = path + '&sessiontrainerid=' + $(this).attr('socid');
								} else {
									if($(this).attr('model') == 'fiche_pedago_modules' || $(this).attr('model') == 'fiche_pedago'){
										adresse = $(this).prev().prev().attr('href');
										idform = adresse.substr(adresse.indexOf('idform=')+7);
										path = path + '&idform=' + idform;
									} else if($(this).attr('model') == 'courrier'){
										adresse = $(this).prev().prev().attr('href');
										goodlink = $(this).prev().prev().attr('name');
                                        if (typeof goodlink ==='undefined') {
                                            adresse = $(this).prev().prev().prev().attr('href');
                                        }
										cour = adresse.substr(adresse.indexOf('&cour=')+6);
										if(cour.indexOf('&') !== -1){
											courrier = cour.substr(0, cour.indexOf('&'));
										} else {
											courrier = cour.substr(0);
										}
										path = path + '&cour=' + courrier + '&socid=' + $(this).attr('socid');
									} else {
										path = path + '&socid=' + $(this).attr('socid');
									}
								}
							<?php
						} elseif($page === 'document_by_trainee') {
							?>path = path + '&sessiontraineeid=' + $(this).attr('socid');<?php
						}

					?>

					document.location.href=path;

				});

			});

		</script>

		<?php

	}

}
