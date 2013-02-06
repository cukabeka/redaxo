<?php

/**
 * Verwaltung der Inhalte. EditierModul / Metadaten ...
 * @package redaxo5
 */

/*
// TODOS:
// - alles vereinfachen
// - <?php ?> $ Problematik bei REX_ACTION
*/

$content = '';

$article_id  = rex_request('article_id',  'int');
$clang       = rex_request('clang',       'int');
$slice_id    = rex_request('slice_id',    'int', '');
$function    = rex_request('function',    'string');

$article_id = rex_article::getArticleById($article_id) instanceof rex_article ? $article_id : 0;
$clang = rex_clang::exists($clang) ? $clang : rex::getProperty('start_clang_id');

$article_revision = 0;
$slice_revision = 0;
$template_attributes = array();

$warning = '';
$global_warning = '';
$info = '';
$global_info = '';

$article = rex_sql::factory();
$article->setQuery('
    SELECT
      article.*, template.attributes as template_attributes
    FROM
      ' . rex::getTablePrefix() . 'article as article
    LEFT JOIN ' . rex::getTablePrefix() . "template as template
      ON template.id=article.template_id
    WHERE
      article.id='$article_id'
      AND clang=$clang");


if ($article->getRows() == 1) {
  // ----- ctype holen
  $template_attributes = $article->getArrayValue('template_attributes');

  // Für Artikel ohne Template
  if (!is_array($template_attributes))
    $template_attributes = array();

  $ctypes = isset($template_attributes['ctype']) ? $template_attributes['ctype'] : array(); // ctypes - aus dem template

  $ctype = rex_request('ctype', 'int', 1);
  if (!array_key_exists($ctype, $ctypes))
    $ctype = 1; // default = 1

  // ----- Artikel wurde gefunden - Kategorie holen
  $OOArt = rex_article::getArticleById($article_id, $clang);
  $category_id = $OOArt->getCategoryId();


  // ----- Request Parameter
  $mode     = rex_request('mode', 'string');
  $function = rex_request('function', 'string');
  $warning  = htmlspecialchars(rex_request('warning', 'string'));
  $info     = htmlspecialchars(rex_request('info', 'string'));

  // ----- mode defs
  if ($mode != 'meta' && $mode != 'metafuncs')
    $mode = 'edit';

  // ----- Languages
  $language_add = '&amp;mode=' . $mode . '&amp;category_id=' . $category_id . '&amp;article_id=' . $article_id;
  require rex_path::addon('structure', 'functions/function_rex_languages.php');



  // ----- Titel anzeigen
  echo rex_view::title(rex_i18n::msg('content'));

  // ----- category pfad und rechte
  require rex_path::addon('structure', 'functions/function_rex_category.php');


  if (rex_be_controller::getCurrentPagePart(1) == 'content' && $article_id > 0) {

    $term = ($article->getValue('startpage') == 1) ? rex_i18n::msg('start_article') : rex_i18n::msg('article');
    $catname = str_replace(' ', '&nbsp;', htmlspecialchars($article->getValue('name')));
    // TODO: if admin or recht advanced -> $KATout .= " [$article_id]";

    $navigation = array();
    $navigation[] = array(
      'href' => rex_url::backendPage('content', array('mode' => 'edit', 'article_id' => $article_id, 'clang' => $clang)),
      'title' => $catname
    );

    $fragment = new rex_fragment();
    $fragment->setVar('title', $term);
    $fragment->setVar('items', $navigation, false);
    echo $fragment->parse('core/navigations/path.tpl');
    unset($fragment);

  }



  // ----- EXTENSION POINT
  echo rex_extension::registerPoint('PAGE_CONTENT_HEADER', '',
    array(
      'article_id' => $article_id,
      'clang' => $clang,
      'function' => $function,
      'mode' => $mode,
      'slice_id' => $slice_id,
      'page' => 'content',
      'ctype' => $ctype,
      'category_id' => $category_id,
      'article_revision' => &$article_revision,
      'slice_revision' => &$slice_revision,
    )
  );

  // --------------------- SEARCH BAR

  require_once $this->getAddon()->getPath('functions/function_rex_searchbar.php');
  echo rex_structure_searchbar();

  // ----------------- HAT USER DIE RECHTE AN DIESEM ARTICLE ODER NICHT
  if (!rex::getUser()->getComplexPerm('structure')->hasCategoryPerm($category_id)) {
    // ----- hat keine rechte an diesem artikel
    echo rex_view::warning(rex_i18n::msg('no_rights_to_edit'));
  } else {
    // ----- hat rechte an diesem artikel

    // ------------------------------------------ Slice add/edit/delete
    if (rex_request('save', 'boolean') && in_array($function, array('add', 'edit', 'delete'))) {
      // ----- check module

      $CM = rex_sql::factory();
      if ($function == 'edit' || $function == 'delete') {
        // edit/ delete
        $CM->setQuery('SELECT * FROM ' . rex::getTablePrefix() . 'article_slice LEFT JOIN ' . rex::getTablePrefix() . 'module ON ' . rex::getTablePrefix() . 'article_slice.module_id=' . rex::getTablePrefix() . 'module.id WHERE ' . rex::getTablePrefix() . "article_slice.id='$slice_id' AND clang=$clang");
        if ($CM->getRows() == 1)
          $module_id = $CM->getValue('' . rex::getTablePrefix() . 'article_slice.module_id');
      } else {
        // add
        $module_id = rex_post('module_id', 'int');
        $CM->setQuery('SELECT * FROM ' . rex::getTablePrefix() . 'module WHERE id=' . $module_id);
      }

      if ($CM->getRows() != 1) {
        // ------------- START: MODUL IST NICHT VORHANDEN
        $global_warning = rex_i18n::msg('module_not_found');
        $slice_id = '';
        $function = '';
        // ------------- END: MODUL IST NICHT VORHANDEN
      } else {
        // ------------- MODUL IST VORHANDEN

        // ----- RECHTE AM MODUL ?
        if ($function != 'delete' && !rex_template::hasModule($template_attributes, $ctype, $module_id)) {
          $global_warning = rex_i18n::msg('no_rights_to_this_function');
          $slice_id = '';
          $function = '';

        } elseif (!rex::getUser()->getComplexPerm('modules')->hasPerm($module_id)) {
          // ----- RECHTE AM MODUL: NEIN
          $global_warning = rex_i18n::msg('no_rights_to_this_function');
          $slice_id = '';
          $function = '';
        } else {
          // ----- RECHTE AM MODUL: JA

          // ***********************  daten einlesen

          $newsql = rex_sql::factory();
          // $newsql->debugsql = true;

          // ----- PRE SAVE ACTION [ADD/EDIT/DELETE]
          $action = new rex_article_action($module_id, $function, $newsql);
          $action->setRequestValues();
          $action->exec(rex_article_action::PRESAVE);
          $action_message = implode('<br />', $action->getMessages());
          // ----- / PRE SAVE ACTION

          // Werte werden aus den REX_ACTIONS übernommen wenn SAVE=true
          if (!$action->getSave()) {
            // ----- DONT SAVE/UPDATE SLICE
            if ($action_message != '')
              $warning = $action_message;
            elseif ($function == 'delete')
              $warning = rex_i18n::msg('slice_deleted_error');
            else
              $warning = rex_i18n::msg('slice_saved_error');

          } else {
            if ($action_message)
              $action_message .= '<br />';

            // ----- SAVE/UPDATE SLICE
            if ($function == 'add' || $function == 'edit') {

              $sliceTable = rex::getTablePrefix() . 'article_slice';
              $newsql->setTable($sliceTable);

              if ($function == 'edit') {
                $newsql->setWhere(array('id' => $slice_id));
              } elseif ($function == 'add') {
                // determine prior value to get the new slice into the right order
                $prevSlice = rex_sql::factory();
                // $prevSlice->debugsql = true;
                if ($slice_id == -1) // -1 is used when adding after the last article-slice
                  $prevSlice->setQuery('SELECT IFNULL(MAX(prior),0)+1 as prior FROM ' . $sliceTable . ' WHERE article_id=' . $article_id . ' AND clang=' . $clang . ' AND ctype=' . $ctype . ' AND revision=' . $slice_revision);
                else
                  $prevSlice->setQuery('SELECT * FROM ' . $sliceTable . ' WHERE id=' . $slice_id);

                $prior = $prevSlice->getValue('prior');

                $newsql->setValue('article_id', $article_id);
                $newsql->setValue('module_id', $module_id);
                $newsql->setValue('clang', $clang);
                $newsql->setValue('ctype', $ctype);
                $newsql->setValue('revision', $slice_revision);
                $newsql->setValue('prior', $prior);
              }

              if ($function == 'edit') {
                $newsql->addGlobalUpdateFields();
                try {
                  $newsql->update();
                  $info = $action_message . rex_i18n::msg('block_updated');

                  // ----- EXTENSION POINT
                  $info = rex_extension::registerPoint('SLICE_UPDATED', $info,
                    array(
                      'article_id' => $article_id,
                      'clang' => $clang,
                      'function' => $function,
                      'mode' => $mode,
                      'slice_id' => $slice_id,
                      'page' => 'content',
                      'ctype' => $ctype,
                      'category_id' => $category_id,
                      'module_id' => $module_id,
                      'article_revision' => &$article_revision,
                      'slice_revision' => &$slice_revision,
                    )
                  );
                } catch (rex_sql_exception $e) {
                  $warning = $action_message . $e->getMessage();
                }

              } elseif ($function == 'add') {
                $newsql->addGlobalUpdateFields();
                $newsql->addGlobalCreateFields();

                try {
                  $newsql->insert();

                  rex_sql_util::organizePriorities(
                    rex::getTable('article_slice'),
                    'prior',
                    'article_id=' . $article_id . ' AND clang=' . $clang . ' AND ctype=' . $ctype . ' AND revision=' . $slice_revision,
                    'prior, updatedate DESC'
                  );

                  $info = $action_message . rex_i18n::msg('block_added');
                  $slice_id = $newsql->getLastId();
                  $function = '';

                  // ----- EXTENSION POINT
                  $info = rex_extension::registerPoint('SLICE_ADDED', $info,
                    array(
                      'article_id' => $article_id,
                      'clang' => $clang,
                      'function' => $function,
                      'mode' => $mode,
                      'slice_id' => $slice_id,
                      'page' => 'content',
                      'ctype' => $ctype,
                      'category_id' => $category_id,
                      'module_id' => $module_id,
                      'article_revision' => &$article_revision,
                      'slice_revision' => &$slice_revision,
                    )
                  );
                } catch (rex_sql_exception $e) {
                  $warning = $action_message . $e->getMessage();
                }
              }
            } else {
              // make delete
              if (rex_content_service::deleteSlice($slice_id)) {
                $global_info = rex_i18n::msg('block_deleted');

                // ----- EXTENSION POINT
                $global_info = rex_extension::registerPoint('SLICE_DELETED', $global_info,
                  array(
                    'article_id' => $article_id,
                    'clang' => $clang,
                    'function' => $function,
                    'mode' => $mode,
                    'slice_id' => $slice_id,
                    'page' => 'content',
                    'ctype' => $ctype,
                    'category_id' => $category_id,
                    'module_id' => $module_id,
                    'article_revision' => &$article_revision,
                    'slice_revision' => &$slice_revision,
                  )
                );
              } else {
                $global_warning = rex_i18n::msg('block_not_deleted');
              }
            }
            // ----- / SAVE SLICE

            // ----- artikel neu generieren
            $EA = rex_sql::factory();
            $EA->setTable(rex::getTablePrefix() . 'article');
            $EA->setWhere(array('id' => $article_id, 'clang' => $clang));
            $EA->addGlobalUpdateFields();
            $EA->update();
            rex_article_cache::delete($article_id, $clang);

            rex_extension::registerPoint('ART_CONTENT_UPDATED', '',
              array(
                'id' => $article_id,
                'clang' => $clang
              )
            );

            // ----- POST SAVE ACTION [ADD/EDIT/DELETE]
            $action->exec(rex_article_action::POSTSAVE);
            if ($messages = $action->getMessages()) {
              $info .= '<br />' . implode('<br />', $messages);
            }
            // ----- / POST SAVE ACTION

            // Update Button wurde gedrückt?
            // TODO: Workaround, da IE keine Button Namen beim
            // drücken der Entertaste übermittelt
            if (rex_post('btn_save', 'string')) {
              $function = '';
            }
          }
        }
      }
    }
    // ------------------------------------------ END: Slice add/edit/delete

    // ------------------------------------------ START: COPY LANG CONTENT
    if (rex_post('copycontent', 'boolean')) {
      $clang_a = rex_post('clang_a', 'int');
      $clang_b = rex_post('clang_b', 'int');
      $user = rex::getUser();
      if ($user->hasPerm('copyContent[]') && $user->getComplexPerm('clang')->hasPerm($clang_a) && $user->getComplexPerm('clang')->hasPerm($clang_b)) {
        if (rex_content_service::copyContent($article_id, $article_id, $clang_a, $clang_b, 0, $slice_revision))
          $info = rex_i18n::msg('content_contentcopy');
        else
          $warning = rex_i18n::msg('content_errorcopy');
      } else {
        $warning = rex_i18n::msg('no_rights_to_this_function');
      }
    }
    // ------------------------------------------ END: COPY LANG CONTENT

    // ------------------------------------------ START: MOVE ARTICLE
    if (rex_post('movearticle', 'boolean') && $category_id != $article_id) {
      $category_id_new = rex_post('category_id_new', 'int');
      if (rex::getUser()->hasPerm('moveArticle[]') && rex::getUser()->getComplexPerm('structure')->hasCategoryPerm($category_id_new)) {
        if (rex_article_service::moveArticle($article_id, $category_id, $category_id_new)) {
          $info = rex_i18n::msg('content_articlemoved');
          ob_end_clean();
          header('Location: ' . rex_url::backendPage('content', array('mode' => 'meta', 'article_id' => $article_id, 'clang' => $clang, 'ctype' => $ctype, 'info' => urlencode($info))));
          exit;
        } else {
          $warning = rex_i18n::msg('content_errormovearticle');
        }
      } else {
        $warning = rex_i18n::msg('no_rights_to_this_function');
      }
    }
    // ------------------------------------------ END: MOVE ARTICLE

    // ------------------------------------------ START: COPY ARTICLE
    if (rex_post('copyarticle', 'boolean')) {
      $category_copy_id_new = rex_post('category_copy_id_new', 'int');
      if (rex::getUser()->hasPerm('copyArticle[]') && rex::getUser()->getComplexPerm('structure')->hasCategoryPerm($category_copy_id_new)) {
        if (($new_id = rex_article_service::copyArticle($article_id, $category_copy_id_new)) !== false) {
          $info = rex_i18n::msg('content_articlecopied');
          ob_end_clean();
          header('Location: ' . rex_url::backendPage('content', array('mode' => 'meta', 'article_id' => $new_id, 'clang' => $clang, 'ctype' => $ctype, 'info' => urlencode($info))));
          exit;
        } else {
          $warning = rex_i18n::msg('content_errorcopyarticle');
        }
      } else {
        $warning = rex_i18n::msg('no_rights_to_this_function');
      }
    }
    // ------------------------------------------ END: COPY ARTICLE

    // ------------------------------------------ START: MOVE CATEGORY
    if (rex_post('movecategory', 'boolean')) {
      $category_id_new = rex_post('category_id_new', 'int');
      if (rex::getUser()->hasPerm('moveCategory[]') && rex::getUser()->getComplexPerm('structure')->hasCategoryPerm($article->getValue('re_id')) && rex::getUser()->getComplexPerm('structure')->hasCategoryPerm($category_id_new)) {
        if ($category_id != $category_id_new && rex_category_service::moveCategory($category_id, $category_id_new)) {
          $info = rex_i18n::msg('category_moved');
          ob_end_clean();
          header('Location: ' . rex_url::backendPage('content', array('mode' => 'meta', 'article_id' => $category_id, 'clang' => $clang, 'ctype' => $ctype, 'info' => urlencode($info))));
          exit;
        } else {
          $warning = rex_i18n::msg('content_error_movecategory');
        }
      } else {
        $warning = rex_i18n::msg('no_rights_to_this_function');
      }
    }
    // ------------------------------------------ END: MOVE CATEGORY

    // ------------------------------------------ START: SAVE METADATA
    if (rex_post('savemeta', 'boolean')) {
      $meta_article_name = rex_post('meta_article_name', 'string');

      $meta_sql = rex_sql::factory();
      $meta_sql->setTable(rex::getTablePrefix() . 'article');
      // $meta_sql->debugsql = 1;
      $meta_sql->setWhere(array('id' => $article_id, 'clang' => $clang));
      $meta_sql->setValue('name', $meta_article_name);
      $meta_sql->addGlobalUpdateFields();

      try {
        $meta_sql->update();

        $article->setQuery('SELECT * FROM ' . rex::getTablePrefix() . "article WHERE id='$article_id' AND clang='$clang'");
        $info = rex_i18n::msg('metadata_updated');

        rex_article_cache::delete($article_id, $clang);

        // ----- EXTENSION POINT
        $info = rex_extension::registerPoint('ART_META_UPDATED', $info, array(
          'id' => $article_id,
          'clang' => $clang,
          'name' => $meta_article_name,
        ));
      } catch (rex_sql_exception $e) {
        $warning = $e->getMessage();
      }
    }
    // ------------------------------------------ END: SAVE METADATA

    // ------------------------------------------ START: CONTENT HEAD MENUE
    $num_ctypes = count($ctypes);

    $content_navi_left        = array();
    $content_navi_right       = array();
    $content_navi_text_left   = '';
    $content_navi_text_right  = '';

    $ctype_menu = '';
    if ($num_ctypes > 0) {

      foreach ($ctypes as $key => $val) {

        $n = array();
        $n['title'] = rex_i18n::translate($val);
        $n['href'] = rex_url::backendPage('content', array('mode' => 'edit', 'category_id' => $category_id, 'article_id' => $article_id, 'clang' => $clang, 'ctype' => $key));
        if ($key == $ctype && $mode == 'edit') {
          $n['linkClasses'] = array('rex-active');
          $n['itemClasses'] = array('rex-active');
        }
        $content_navi_left[] = $n;

      }

      // ----- EXTENSION POINT
      $content_navi_left = rex_extension::registerPoint('PAGE_CONTENT_CTYPE_MENU', $content_navi_left,
        array(
          'article_id' => $article_id,
          'clang' => $clang,
          'function' => $function,
          'mode' => $mode,
          'slice_id' => $slice_id
        )
      );

      if ($num_ctypes > 1)
        $ctype_menu .= rex_i18n::msg('content_types');
      else
        $ctype_menu .= rex_i18n::msg('content_type');


      if ($mode == 'edit') {
        $content_navi_text_left .= '<span class="rex-active">' . rex_i18n::msg('edit_mode') . '</span>';
      } else {
        $content_navi_text_left .= rex_i18n::msg('edit_mode');
      }
    } else {
      $n = array();
      $n['title'] = rex_i18n::msg('edit_mode');
      $n['href'] = rex_url::backendPage('content', array('mode' => 'edit', 'article_id' => $article_id, 'clang' => $clang, 'ctype' => $ctype));
      if ($mode == 'edit') {
        $n['linkClasses'] = array('rex-active');
        $n['itemClasses'] = array('rex-active');
      }
      $content_navi_left[] = $n;
    }

    $content_navi_text_right .= rex_i18n::msg('article') . ' <a href="' . rex_getUrl($article_id, $clang) . '" onclick="window.open(this.href); return false;" data-pjax="false">' . rex_i18n::msg('show') . '</a>';


    $n = array();
    $n['title'] = rex_i18n::msg('metadata');
    $n['href'] = rex_url::backendPage('content', array('mode' => 'meta', 'article_id' => $article_id, 'clang' => $clang, 'ctype' => $ctype));
    if ($mode == 'meta') {
      $n['linkClasses'] = array('rex-active');
      $n['itemClasses'] = array('rex-active');
    }
    $content_navi_right[] = $n;


    $n = array();
    $n['title'] = rex_i18n::msg('metafuncs');
    $n['href'] = rex_url::backendPage('content', array('mode' => 'metafuncs', 'article_id' => $article_id, 'clang' => $clang, 'ctype' => $ctype));
    if ($mode == 'metafuncs') {
      $n['linkClasses'] = array('rex-active');
      $n['itemClasses'] = array('rex-active');
    }
    $content_navi_right[] = $n;

    // ----- EXTENSION POINT
    $content_navi_right = rex_extension::registerPoint('PAGE_CONTENT_MENU', $content_navi_right,
      array(
        'article_id' => $article_id,
        'clang' => $clang,
        'function' => $function,
        'mode' => $mode,
        'slice_id' => $slice_id
      )
    );


    $fragment = new rex_fragment();
    $fragment->setVar('navigation_left', $content_navi_left, false);
    $fragment->setVar('navigation_right', $content_navi_right, false);
    $fragment->setVar('text_left', $content_navi_text_left, false);
    $fragment->setVar('text_right', $content_navi_text_right, false);
    echo $fragment->parse('core/navigations/content.tpl');


    // ------------------------------------------ END: CONTENT HEAD MENUE

    // ------------------------------------------ WARNING
    if ($global_warning != '') {
      echo rex_view::warning($global_warning);
    }
    if ($global_info != '') {
      echo rex_view::success($global_info);
    }

    // --------------------------------------------- API MESSAGES
    echo rex_api_function::getMessage();

    if ($warning != '') {
      echo rex_view::warning($warning);
    }
    if ($info != '') {
      echo rex_view::success($info);
    }


    // ------------------------------------------ START: MODULE EDITIEREN/ADDEN ETC.
    if ($mode == 'edit') {

      $CONT = new rex_article_content_editor;
      $CONT->getContentAsQuery();
      $CONT->info = $info;
      $CONT->warning = $warning;
      $CONT->template_attributes = $template_attributes;
      $CONT->setArticleId($article_id);
      $CONT->setSliceId($slice_id);
      $CONT->setMode($mode);
      $CONT->setCLang($clang);
      $CONT->setEval(true);
      $CONT->setSliceRevision($slice_revision);
      $CONT->setFunction($function);
      $content .= $CONT->getArticle($ctype);

      echo rex_view::contentBlock($content);

    // ------------------------------------------ START: META VIEW
    } elseif ($mode == 'meta') {

      $content .= '
        <div class="rex-form" id="rex-form-content-metamode">
          <form action="' . rex_url::currentBackendPage(array('mode' => 'meta', 'article_id' => $article_id, 'clang' => $clang)) . '" method="post" enctype="multipart/form-data" id="REX_FORM">
            <fieldset>
              <h2>' . rex_i18n::msg('general') . '</h2>

                <input type="hidden" name="save" value="1" />
                <input type="hidden" name="ctype" value="' . $ctype . '" />
                ';

      $formElements = array();

      $n = array();
      $n['label'] = '<label for="rex-id-meta-article-name">' . rex_i18n::msg('name_description') . '</label>';
      $n['field'] = '<input type="text" id="rex-id-meta-article-name" name="meta_article_name" value="' . htmlspecialchars($article->getValue('name')) . '" />';
      $formElements[] = $n;

      $fragment = new rex_fragment();
      $fragment->setVar('elements', $formElements, false);
      $content .= $fragment->parse('core/form/form.tpl');


      // ----- EXTENSION POINT
      $content .= rex_extension::registerPoint('ART_META_FORM', '', array(
        'id' => $article_id,
        'clang' => $clang,
        'article' => $article
      ));

      $content .= '</fieldset>';

      $formElements = array();

      $n = array();
      $n['field'] = '<button class="rex-button" type="submit" name="savemeta"' . rex::getAccesskey(rex_i18n::msg('update_metadata'), 'save') . '>' . rex_i18n::msg('update_metadata') . '</button>';
      $formElements[] = $n;

      $fragment = new rex_fragment();
      $fragment->setVar('elements', $formElements, false);
      $content .= $fragment->parse('core/form/submit.tpl');


      // ----- EXTENSION POINT
      $content .= rex_extension::registerPoint('ART_META_FORM_SECTION', '', array(
        'id' => $article_id,
        'clang' => $clang
      ));

      $content .= '
                  </form>
                </div>';

    echo rex_view::contentBlock($content);

    // ------------------------------------------ START: META FUNCS
    } elseif ($mode == 'metafuncs') {

      $content .= '
        <div class="rex-form" id="rex-form-content-metamode">
          <form action="' . rex_url::currentBackendPage(array('mode' => 'metafuncs', 'article_id' => $article_id, 'clang' => $clang)) . '" method="post" enctype="multipart/form-data" id="REX_FORM">
                <input type="hidden" name="save" value="1" />
                <input type="hidden" name="ctype" value="' . $ctype . '" />
                <input type="hidden" name="rex-api-call" id="apiField">
                ';


      $isStartpage = $article->getValue('startpage') == 1;
      $out = '';

      // --------------------------------------------------- ZUM STARTARTICLE MACHEN START
      if (rex::getUser()->hasPerm('article2startpage[]')) {
        $out .= '
            <fieldset>
              <h2>' . rex_i18n::msg('content_startarticle') . '</h2>';

        $formElements = array();

        $n = array();
        if (!$isStartpage && $article->getValue('re_id') == 0)
          $n['field'] = '<span class="rex-form-read">' . rex_i18n::msg('content_nottostartarticle') . '</span>';
        elseif ($isStartpage)
          $n['field'] = '<span class="rex-form-read">' . rex_i18n::msg('content_isstartarticle') . '</span>';
        else
          $n['field'] = '<button class="rex-button" type="submit" name="article2startpage" data-confirm="' . rex_i18n::msg('content_tostartarticle') . '?" onclick="jQuery(\'#apiField\').val(\'article2startpage\');">' . rex_i18n::msg('content_tostartarticle') . '</button>';
        $formElements[] = $n;

        $fragment = new rex_fragment();
        $fragment->setVar('elements', $formElements, false);
        $out .= $fragment->parse('core/form/form.tpl');



        $out .= '</fieldset>';

        $content .= rex_view::contentBlock($out);
      }



      // --------------------------------------------------- ZUM STARTARTICLE MACHEN END

      // --------------------------------------------------- IN KATEGORIE UMWANDELN START
      $out = '';
      if (!$isStartpage && rex::getUser()->hasPerm('article2category[]')) {
        $out .= '
            <fieldset>
              <h2>' . rex_i18n::msg('content_category') . '</h2>';


        $formElements = array();

        $n = array();
        $n['field'] = '<button class="rex-button" type="submit" name="article2category" data-confirm="' . rex_i18n::msg('content_tocategory') . '?" onclick="jQuery(\'#apiField\').val(\'article2category\');">' . rex_i18n::msg('content_tocategory') . '</button>';
        $formElements[] = $n;

        $fragment = new rex_fragment();
        $fragment->setVar('elements', $formElements, false);
        $out .= $fragment->parse('core/form/form.tpl');


        $out .= '</fieldset>';

        $content .= rex_view::contentBlock($out);
      }
      // --------------------------------------------------- IN KATEGORIE UMWANDELN END

      // --------------------------------------------------- IN ARTIKEL UMWANDELN START
      $out = '';
      if ($isStartpage && rex::getUser()->hasPerm('category2article[]') && rex::getUser()->getComplexPerm('structure')->hasCategoryPerm($article->getValue('re_id'))) {
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT pid FROM ' . rex::getTablePrefix() . 'article WHERE re_id=' . $article_id . ' LIMIT 1');
        $emptyCategory = $sql->getRows() == 0;

        $out .= '
            <fieldset>
              <h2>' . rex_i18n::msg('content_article') . '</h2>';


        $formElements = array();

        $n = array();
        if (!$emptyCategory)
          $n['field'] = '<span class="rex-form-read">' . rex_i18n::msg('content_nottoarticle') . '</span>';
        else
          $n['field'] = '<button class="rex-button" type="submit" name="category2article" data-confirm="' . rex_i18n::msg('content_toarticle') . '?" onclick="jQuery(\'#apiField\').val(\'category2article\');">' . rex_i18n::msg('content_toarticle') . '</button>';
        $formElements[] = $n;

        $fragment = new rex_fragment();
        $fragment->setVar('elements', $formElements, false);
        $out .= $fragment->parse('core/form/form.tpl');

        $out .= '</fieldset>';

        $content .= rex_view::contentBlock($out);
      }
      // --------------------------------------------------- IN ARTIKEL UMWANDELN END

      // --------------------------------------------------- INHALTE KOPIEREN START
      $out = '';
      $user = rex::getUser();
      if ($user->hasPerm('copyContent[]') && $user->getComplexPerm('clang')->count() > 1) {
        $clang_perm = $user->getComplexPerm('clang')->getClangs();

        $lang_a = new rex_select;
        $lang_a->setId('clang_a');
        $lang_a->setName('clang_a');
        $lang_a->setSize('1');
        foreach ($clang_perm as $key) {
          $val = rex_i18n::translate(rex_clang::get($key)->getName());
          $lang_a->addOption($val, $key);
        }

        $lang_b = new rex_select;
        $lang_b->setId('clang_b');
        $lang_b->setName('clang_b');
        $lang_b->setSize('1');
        foreach ($clang_perm as $key) {
          $val = rex_i18n::translate(rex_clang::get($key)->getName());
          $lang_b->addOption($val, $key);
        }

        $lang_a->setSelected(rex_request('clang_a', 'int', null));
        $lang_b->setSelected(rex_request('clang_b', 'int', null));

        $out .= '
              <fieldset>
                <h2>' . rex_i18n::msg('content_submitcopycontent') . '</h2>';

        $formElements = array();

        $n = array();
        $n['label'] = '<label for="clang_a">' . rex_i18n::msg('content_contentoflang') . '</label>';
        $n['field'] = $lang_a->get();
        $formElements[] = $n;

        $n = array();
        $n['label'] = '<label for="clang_b">' . rex_i18n::msg('content_to') . '</label>';
        $n['field'] = $lang_b->get();
        $formElements[] = $n;

        $fragment = new rex_fragment();
        $fragment->setVar('group', true);
        $fragment->setVar('elements', $formElements, false);
        $out .= $fragment->parse('core/form/form.tpl');


        $formElements = array();

        $n = array();
        $n['field'] = '<button class="rex-button" type="submit" name="copycontent" data-confirm="' . rex_i18n::msg('content_submitcopycontent') . '?">' . rex_i18n::msg('content_submitcopycontent') . '</button>';
        $formElements[] = $n;

        $fragment = new rex_fragment();
        $fragment->setVar('elements', $formElements, false);
        $out .= $fragment->parse('core/form/form.tpl');


        $out .= '</fieldset>';

        $content .= rex_view::contentBlock($out);

      }
      // --------------------------------------------------- INHALTE KOPIEREN ENDE

      // --------------------------------------------------- ARTIKEL VERSCHIEBEN START
      $out = '';
      if (!$isStartpage && rex::getUser()->hasPerm('moveArticle[]')) {

        // Wenn Artikel kein Startartikel dann Selectliste darstellen, sonst...
        $move_a = new rex_category_select(false, false, true, !rex::getUser()->getComplexPerm('structure')->hasMountPoints());
        $move_a->setId('category_id_new');
        $move_a->setName('category_id_new');
        $move_a->setSize('1');
        $move_a->setSelected($category_id);

        $out .= '
              <fieldset>
                <h2>' . rex_i18n::msg('content_submitmovearticle') . '</h2>';


        $formElements = array();

        $n = array();
        $n['label'] = '<label for="category_id_new">' . rex_i18n::msg('move_article') . '</label>';
        $n['field'] = $move_a->get();
        $formElements[] = $n;

        $n = array();
        $n['field'] = '<button class="rex-button" type="submit" name="movearticle" data-confirm="' . rex_i18n::msg('content_submitmovearticle') . '?">' . rex_i18n::msg('content_submitmovearticle') . '</button>';
        $formElements[] = $n;

        $fragment = new rex_fragment();
        $fragment->setVar('elements', $formElements, false);
        $out .= $fragment->parse('core/form/form.tpl');

        $out .= '</fieldset>';

        $content .= rex_view::contentBlock($out);

      }
      // ------------------------------------------------ ARTIKEL VERSCHIEBEN ENDE

      // -------------------------------------------------- ARTIKEL KOPIEREN START
      $out = '';
      if (rex::getUser()->hasPerm('copyArticle[]')) {
        $move_a = new rex_category_select(false, false, true, !rex::getUser()->getComplexPerm('structure')->hasMountPoints());
        $move_a->setName('category_copy_id_new');
        $move_a->setId('category_copy_id_new');
        $move_a->setSize('1');
        $move_a->setSelected($category_id);

        $out .= '
              <fieldset>
                <h2>' . rex_i18n::msg('content_submitcopyarticle') . '</h2>';


        $formElements = array();

        $n = array();
        $n['label'] = '<label for="category_copy_id_new">' . rex_i18n::msg('copy_article') . '</label>';
        $n['field'] = $move_a->get();
        $formElements[] = $n;

        $n = array();
        $n['field'] = '<button class="rex-button" type="submit" name="copyarticle" data-confirm="' . rex_i18n::msg('content_submitcopyarticle') . '?">' . rex_i18n::msg('content_submitcopyarticle') . '</button>';
        $formElements[] = $n;

        $fragment = new rex_fragment();
        $fragment->setVar('elements', $formElements, false);
        $out .= $fragment->parse('core/form/form.tpl');

        $out .= '</fieldset>';

        $content .= rex_view::contentBlock($out);

      }
      // --------------------------------------------------- ARTIKEL KOPIEREN ENDE

      // --------------------------------------------------- KATEGORIE/STARTARTIKEL VERSCHIEBEN START
      $out = '';
      if ($isStartpage && rex::getUser()->hasPerm('moveCategory[]') && rex::getUser()->getComplexPerm('structure')->hasCategoryPerm($article->getValue('re_id'))) {
        $move_a = new rex_category_select(false, false, true, !rex::getUser()->getComplexPerm('structure')->hasMountPoints());
        $move_a->setId('category_id_new');
        $move_a->setName('category_id_new');
        $move_a->setSize('1');
        $move_a->setSelected($article_id);

        $out .= '
              <fieldset>
                <h2>' . rex_i18n::msg('content_submitmovecategory') . '</h2>';


                $formElements = array();

                $n = array();
                $n['label'] = '<label for="category_id_new">' . rex_i18n::msg('move_category') . '</label>';
                $n['field'] = $move_a->get();
                $formElements[] = $n;

                $n = array();
                $n['field'] = '<button class="rex-button" type="submit" name="movecategory" data-confirm="' . rex_i18n::msg('content_submitmovecategory') . '?">' . rex_i18n::msg('content_submitmovecategory') . '</button>';
                $formElements[] = $n;

                $fragment = new rex_fragment();
                $fragment->setVar('elements', $formElements, false);
                $out .= $fragment->parse('core/form/form.tpl');

        $out .= '</fieldset>';

        $content .= rex_view::contentBlock($out);

      }

      // ------------------------------------------------ KATEGROIE/STARTARTIKEL VERSCHIEBEN ENDE

      $content .= '
                  </form>
                </div>';

    echo rex_view::contentBlock($content, '', true, false);

    }


    // ------------------------------------------ END: AUSGABE

  }
}
