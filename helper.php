<?php
/**
 * @package     Joomla.Site
 * @subpackage  mod_articles_thumbnails
 *
 * @copyright	Copyright © 2016 - All rights reserved.
 * @license		GNU General Public License v2.0
 * @author 		Sergio Iglesias (@sergiois)
 */

// no direct access
defined('_JEXEC') or die;

use Joomla\CMS\Factory;

JLoader::register('ContentHelperRoute', JPATH_SITE . '/components/com_content/helpers/route.php');
JModelLegacy::addIncludePath(JPATH_SITE . '/components/com_content/models', 'ContentModel');

class modarticlesthumbnailsHelper
{
	/**
	 * Get the articles to show
	 *
	 * @param	object	$params	Module parameters
	 *
	 * @return	array	Array of items
	 */
	static public function getItems($params)
	{
		// Set application parameters in model
		$app       = JFactory::getApplication();
		$appParams = $app->getParams();

		$option = $app->input->get('option');
		$view   = $app->input->get('view');

		$onlyInArticles = $params->get('show_only_in_articles');

		$relatedArticles = true;

		if ($onlyInArticles)
		{
			$option = $app->input->get('option');
			$view   = $app->input->get('view');

			if (!($option === 'com_content' && $view === 'article'))
			{
				return array();
			}

			$temp = $app->input->getString('id');
			$temp = explode(':', $temp);
			$id   = $temp[0];
		}

		// Get an instance of the generic articles model
		$model     = JModelLegacy::getInstance('Articles', 'ContentModel', array('ignore_request' => true));

		switch ($params->get('related_mode', 'false'))
		{
			case 'category':
				$categoryId = self::getArticleCategory($id);
				$model->setState('filter.category_id', $categoryId);
				break;
			case 'tags':
				$articleIds = self::getByTag($id);

				if (count($articleIds))
				{
					$model->setState('filter.article_id', $articleIds);
				}
				break;
			case 'keywords':
				$articleIds = self::getByKeyword($id, $params);

				if (count($articleIds))
				{
					$model->setState('filter.article_id', $articleIds);
				}
				break;
			case 'title':
				$articleIds = self::getByTitle($id);

				if (count($articleIds))
				{
					$model->setState('filter.article_id', $articleIds);
				}
				break;
				break;
		}

		// Set application parameters in model
		$app       = JFactory::getApplication();
		$appParams = $app->getParams();
		$model->setState('params', $appParams);

		// Set the filters based on the module params
		$model->setState('list.start', 0);
		$model->setState('list.limit', (int) $params->get('count', 3));
		$model->setState('filter.published', 1);
		$model->setState('filter.featured', $params->get('show_front', 1) == 1 ? 'show' : 'hide');

		// Access filter
		$access = !JComponentHelper::getParams('com_content')->get('show_noauth');
		$authorised = JAccess::getAuthorisedViewLevels(JFactory::getUser()->get('id'));
		$model->setState('filter.access', $access);

		// Category filter
		if (!isset($categoryId))
		{
			$model->setState('filter.category_id', $params->get('catid', array()));
		}

		// Filter by language
		$model->setState('filter.language', $app->getLanguageFilter());

		// Ordering
		if ($params->get('ordering') == 'random')
		{
			$model->setState('list.ordering', JFactory::getDbo()->getQuery(true)->Rand());
		}
		else
		{
			$model->setState('list.ordering', 'a.' . $params->get('ordering', 'publish_up'));
			$model->setState('list.direction', $params->get('direction', 'DESC'));
		}

		$items = $model->getItems();

		foreach ($items as &$item)
		{
			$item->slug    = $item->id . ':' . $item->alias;
			$item->catslug = $item->catid . ':' . $item->category_alias;

			if ($access || in_array($item->access, $authorised))
			{
				// We know that user has the privilege to view the article
				$item->link = JRoute::_(ContentHelperRoute::getArticleRoute($item->slug, $item->catid, $item->language));
			}
			else
			{
				$item->link = JRoute::_('index.php?option=com_users&view=login');
			}
		}

		return $items;
	}

	/**
	 * Get current Article Category
	 *
	 * @param	integer	$id	Article id
	 *
	 * @return	integer	Category id
	 */
	public static function getArticleCategory($id)
	{
		$db = JFactory::getDbo();

		$query = $db->getQuery(true);

		// Select the meta keywords from the item
		$query->select('catid')
			->from('#__content')
			->where('id = ' . (int) $id);
		$db->setQuery($query);

		try
		{
			$catId = $db->loadResult();
		}
		catch (RuntimeException $e)
		{
			JFactory::getApplication()->enqueueMessage(JText::_('MOD_ARTICLE_THUMBNAILS_AN_ERROR_HAS_OCCURRED'), 'error');

			$catId = 0;
		}

		return $catId;
	}

	/**
	 * Get current Article Category
	 *
	 * @param	integer	$id	Article id
	 *
	 * @return	integer	Category id
	 */
	public static function getByTitle($id)
	{
		$db = JFactory::getDbo();

		$query = $db->getQuery(true);

		// Get title words
		$query->select('title')
			->from('#__content')
			->where('id = ' . (int) $id);
		$db->setQuery($query);

		try
		{
			$catId = $db->loadResult();
		}
		catch (RuntimeException $e)
		{
			JFactory::getApplication()->enqueueMessage(JText::_('MOD_ARTICLE_THUMBNAILS_AN_ERROR_HAS_OCCURRED'), 'error');

			$catId = 0;
		}

		return $catId;
	}

	/**
	 * Get related articles by Tags
	 *
	 * @param integer $id	Article id
	 *
	 * @return	array	Array of articles
	 */
	public static function getByTag($id)
	{
		$user = Factory::getUser();
		$access = $user->getAuthorisedViewLevels();

		$db = JFactory::getDbo();

		$nullDate = $db->getNullDate();
		$now = time();

		$query = $db->getQuery(true);

		// Get tags from the item
		$query->select('tm.content_item_id')
			->from('#__contentitem_tag_map AS tm')
			->where('tm.tag_id IN (SELECT tag_id FROM rzrmq_contentitem_tag_map WHERE content_item_id = ' . (int) $id . ')');
		$query->join('inner', '#__tags AS t ON t.id = tm.tag_id')
			->where('t.published = 1')
			->where('t.access IN (' . implode(',', $access) . ')')
			->where('(t.publish_up = ' . $db->quote($nullDate) . ' OR t.publish_up >= ' . $db->quote($now) . ')');
		$db->setQuery($query);

		try
		{
			$tagIds = $db->loadColumn();
		}
		catch (RuntimeException $e)
		{
			JFactory::getApplication()->enqueueMessage(JText::_('MOD_ARTICLE_THUMBNAILS_AN_ERROR_HAS_OCCURRED'), 'error');

			$tagIds = array();
		}

		return $tagIds;
	}

	/**
	 * Get related articles by Keyword
	 *
	 * @param	integer	$id	Article id
	 *
	 * @return	array	Array of articles
	 */
	public static function getByKeyword($id, $params)
	{
		$user = Factory::getUser();
		$access = $user->getAuthorisedViewLevels();

		$db = Factory::getDbo();

		$nullDate = $db->getNullDate();
		$now = time();
		$maximum = $params->get('count', 3);

		$query = $db->getQuery(true);

		// Select the meta keywords from the item
		$query->select('metakey')
			->from('#__content')
			->where('id = ' . (int) $id);
		$db->setQuery($query);

		try
		{
			$metakey = trim($db->loadResult());
		}
		catch (RuntimeException $e)
		{
			JFactory::getApplication()->enqueueMessage(JText::_('MOD_ARTICLE_THUMBNAILS_AN_ERROR_HAS_OCCURRED'), 'error');

			return array();
		}

		// Explode the meta keys on a comma
		$keys  = explode(',', $metakey);
		$likes = array();

		// Assemble any non-blank word(s)
		foreach ($keys as $key)
		{
			$key = trim($key);

			if ($key)
			{
				$likes[] = $db->escape($key);
			}
		}

		if (count($likes))
		{
			// Select other items based on the metakey field 'like' the keys found
			$query->clear()
				->select('a.id')
				->from('#__content AS a')
				->where('a.id != ' . (int) $id)
				->where('a.state = 1')
				->where('a.access IN (' . implode(',', $access) . ')');

				$wheres = array();

			foreach ($likes as $keyword)
			{
				$wheres[] = 'a.metakey LIKE ' . $db->quote('%' . $keyword . '%');
			}

			$query->where('(' . implode(' OR ', $wheres) . ')')
				->where('(a.publish_up = ' . $db->quote($nullDate) . ' OR a.publish_up >= ' . $db->quote($now) . ')')
				->where('(a.publish_down = ' . $db->quote($nullDate) . ' OR a.publish_down <= ' . $db->quote($now) . ')');

			// Filter by language
			if (JLanguageMultilang::isEnabled())
			{
				$query->where('a.language in (' . $db->quote(JFactory::getLanguage()->getTag()) . ',' . $db->quote('*') . ')');
			}

			// Ordering
			if ($params->get('ordering') == 'random')
			{
				$query->order(JFactory::getDbo()->getQuery(true)->Rand());
			}
			else
			{
				$query->order('a.' . $params->get('ordering', 'publish_up') . ' ' . $params->get('direction', 'DESC'));
			}

			$db->setQuery($query, 0, $maximum);

			try
			{
				$articleIds = $db->loadColumn();
			}
			catch (RuntimeException $e)
			{
				JFactory::getApplication()->enqueueMessage(JText::_('JERROR_AN_ERROR_HAS_OCCURRED'), 'error');

				return array();
			}

			return $articleIds;
		}
	}
}
