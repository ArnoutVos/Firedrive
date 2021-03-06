<?php

/**
 * @package     Firedrive
 * @author      Giovanni Mansillo
 * @license     GNU General Public License version 2 or later; see LICENSE.md
 * @copyright   Firedrive
 */
defined('_JEXEC') or die;

JLoader::register('FiredriveHelper', JPATH_ADMINISTRATOR . '/components/com_firedrive/helpers/firedrive.php');

/**
 * Document model.
 *
 * @since  1.6
 */
class FiredriveModelDocument extends JModelAdmin
{

	/**
	 * The type alias for this content type.
	 *
	 * @var    string
	 * @since  3.2
	 */
	public $typeAlias = 'com_firedrive.document';
	/**
	 * The prefix to use with controller messages.
	 *
	 * @var    string
	 * @since  1.6
	 */
	protected $text_prefix = 'COM_FIREDRIVE_DOCUMENT';
	/**
	 * Batch copy/move command. If set to false, the batch copy/move command is not supported
	 *
	 * @var  string
	 * @since  5.1.2
	 */
	protected $batch_copymove = 'category_id';

	/**
	 * Allowed batch commands
	 *
	 * @var  array
	 * @since   5.2.1
	 */
	protected $batch_commands = array(
		'client_id'   => 'batchClient',
		'language_id' => 'batchLanguage'
	);

	/**
	 * Method to delete one or more records.
	 *
	 * @param   array &$pks An array of record primary keys.
	 *
	 * @return  boolean  True if successful, false if an error occurs.
	 *
	 * @since 5.2.1
	 */
	public function delete(&$pks)
	{
		$dispatcher = JEventDispatcher::getInstance();
		$pks        = (array) $pks;
		$table      = $this->getTable();
		jimport('joomla.filesystem.file');
		jimport('joomla.filesystem.folder');

		$db = JFactory::getDbo();

		// Include the content plugins for the on delete events.
		JPluginHelper::importPlugin('content');

		// Iterate the items to delete each one.
		foreach ($pks as $i => $pk)
		{

			if ($table->load($pk))
			{

				if ($this->canDelete($table))
				{

					$context = $this->option . '.' . $this->name;

					// Trigger the onContentBeforeDelete event.
					$result = $dispatcher->trigger($this->event_before_delete, [$context, $table]);

					if (in_array(false, $result, true))
					{
						$this->setError($table->getError());

						return false;
					}

					$query = $db->getQuery(true);
					$query
						->select($db->quoteName('file_name'))
						->from($db->quoteName('#__firedrive'))
						->where($db->quoteName('id') . ' = ' . $pk);
					$db->setQuery($query);

					$file_name = $db->loadResult();

					if (!$table->delete($pk))
					{
						$this->setError($table->getError());

						return false;
					}
					else
					{

						if (!JFile::delete($file_name))
						{
							JFactory::getApplication()->enqueueMessage(JText::_('COM_FIREDRIVE_ERROR_DELETING') . ': ' . $file_name, 'error');
						}
						else
						{
							$path_parts = pathinfo($file_name);
							JFolder::delete($path_parts['dirname']);
						}
					}
					// Trigger the onContentAfterDelete event.
					$dispatcher->trigger($this->event_after_delete, [$context, $table]);
				}
				else
				{

					// Prune items that you can't change.
					unset($pks[$i]);
					$error = $this->getError();
					if ($error)
					{
						JLog::add($error, JLog::WARNING, 'jerror');

						return false;
					}
					else
					{
						JLog::add(JText::_('JLIB_APPLICATION_ERROR_DELETE_NOT_PERMITTED'), JLog::WARNING, 'jerror');

						return false;
					}
				}
			}
			else
			{
				$this->setError($table->getError());

				return false;
			}
		}

		// Clear the component's cache
		$this->cleanCache();

		return true;
	}

	/**
	 * Method to test whether a record can be deleted.
	 *
	 * @param   object $record A record object.
	 *
	 * @return  boolean  True if allowed to delete the record. Defaults to the permission set in the component.
	 *
	 * @since   1.6
	 */
	protected function canDelete($record)
	{
		if (!empty($record->id))
		{
			if ($record->state != -2)
			{
				return false;
			}

			if (!empty($record->catid))
			{
				return JFactory::getUser()->authorise('core.delete', 'com_firedrive.category.' . (int) $record->catid);
			}

			return parent::canDelete($record);
		}
	}

	/**
	 * Method to get the record form.
	 *
	 * @param   array   $data     Data for the form. [optional]
	 * @param   boolean $loadData True if the form is to load its own data (default case), false if not. [optional]
	 *
	 * @return  JForm|boolean  A JForm object on success, false on failure
	 *
	 * @since   1.6
	 */
	public function getForm($data = array(), $loadData = true)
	{
		// Get the form.
		$form = $this->loadForm('com_firedrive.document', 'document', array('control' => 'jform', 'load_data' => $loadData));

		if (empty($form))
		{
			return false;
		}

		// Determine correct permissions to check.
		if ($this->getState('document.id'))
		{
			// Existing record. Can only edit in selected categories.
			$form->setFieldAttribute('catid', 'action', 'core.edit');
		}
		else
		{
			// New record. Can only create in selected categories.
			$form->setFieldAttribute('catid', 'action', 'core.create');
		}

		// Modify the form based on access controls.
		if (!$this->canEditState((object) $data))
		{
			// Disable fields for display.
			$form->setFieldAttribute('ordering', 'disabled', 'true');
			$form->setFieldAttribute('publish_up', 'disabled', 'true');
			$form->setFieldAttribute('publish_down', 'disabled', 'true');
			$form->setFieldAttribute('state', 'disabled', 'true');

			// Disable fields while saving.
			// The controller has already verified this is a record you can edit.
			$form->setFieldAttribute('ordering', 'filter', 'unset');
			$form->setFieldAttribute('publish_up', 'filter', 'unset');
			$form->setFieldAttribute('publish_down', 'filter', 'unset');
			$form->setFieldAttribute('state', 'filter', 'unset');
		}

		return $form;
	}

	/**
	 * Method to test whether a record can have its state changed.
	 *
	 * @param   object $record A record object.
	 *
	 * @return  boolean  True if allowed to change the state of the record. Defaults to the permission set in the component.
	 *
	 * @since   1.6
	 */
	protected function canEditState($record)
	{
		// Check against the category.
		if (!empty($record->catid))
		{
			return JFactory::getUser()->authorise('core.edit.state', 'com_firedrive.category.' . (int) $record->catid);
		}

		// Default to component settings if category not known.
		return parent::canEditState($record);
	}

	/**
	 * Method to save the form data.
	 *
	 * @param   array $data The form data.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   1.6
	 */
	public function save($data)
	{
		$input = JFactory::getApplication()->input;

		JLoader::register('CategoriesHelper', JPATH_ADMINISTRATOR . '/components/com_categories/helpers/categories.php');

		// Cast catid to integer for comparison
		$catid = (int) $data['catid'];

		// Check if New Category exists
		if ($catid > 0)
		{
			$catid = CategoriesHelper::validateCategoryId($data['catid'], 'com_firedrive');
		}

		// Save New Category
		if ($catid == 0 && $this->canCreateCategory())
		{
			$table              = array();
			$table['title']     = $data['catid'];
			$table['parent_id'] = 1;
			$table['extension'] = 'com_firedrive';
			$table['language']  = $data['language'];
			$table['published'] = 1;

			// Create new category and get catid back
			$data['catid'] = CategoriesHelper::createCategory($table);
		}

		// Automatic handling of alias for empty fields
		if (in_array($input->get('task'), array('apply', 'save', 'save2new')) && (!isset($data['id']) || (int) $data['id'] == 0))
		{
			if ($data['alias'] == null)
			{
				if (JFactory::getConfig()->get('unicodeslugs') == 1)
				{
					$data['alias'] = JFilterOutput::stringURLUnicodeSlug($data['title']);
				}
				else
				{
					$data['alias'] = JFilterOutput::stringURLSafe($data['title']);
				}

				$table = JTable::getInstance('Content', 'JTable');

				if ($table->load(array('alias' => $data['alias'], 'catid' => $data['catid'])))
				{
					$msg = JText::_('COM_CONTENT_SAVE_WARNING');
				}

				list($title, $alias) = $this->generateNewTitle($data['catid'], $data['alias'], $data['title']);
				$data['alias'] = $alias;

				if (isset($msg))
				{
					JFactory::getApplication()->enqueueMessage($msg, 'warning');
				}
			}
		}

		// Alter the title for save as copy
		if ($input->get('task') == 'save2copy')
		{
			/** @var FiredriveTableDocument $origTable */
			$origTable = clone $this->getTable();
			$origTable->load($input->getInt('id'));

			if ($data['title'] == $origTable->title)
			{
				list($title, $alias) = $this->generateNewTitle($data['catid'], $data['alias'], $data['title']);
				$data['title'] = $title;
				$data['alias'] = $alias;
			}
			else
			{
				if ($data['alias'] == $origTable->alias)
				{
					$data['alias'] = '';
				}
			}

			$data['state'] = 0;
		}

		return parent::save($data);

	}

	/**
	 * Batch client changes for a group of documents.
	 *
	 * @param   string $value    The new value matching a client.
	 * @param   array  $pks      An array of row IDs.
	 * @param   array  $contexts An array of item contexts.
	 *
	 * @return  boolean  True if successful, false otherwise and internal error is set.
	 *
	 * @since   2.5
	 */
	protected function batchClient($value, $pks, $contexts)
	{
		// Set the variables
		$user = JFactory::getUser();

		/** @var FiredriveTableDocument $table */
		$table = $this->getTable();

		foreach ($pks as $pk)
		{
			if (!$user->authorise('core.edit', $contexts[$pk]))
			{
				$this->setError(JText::_('JLIB_APPLICATION_ERROR_BATCH_CANNOT_EDIT'));

				return false;
			}

			$table->reset();
			$table->load($pk);
			$table->cid = (int) $value;

			if (!$table->store())
			{
				$this->setError($table->getError());

				return false;
			}
		}

		// Clean the cache
		$this->cleanCache();

		return true;
	}

	/**
	 * Returns a JTable object, always creating it.
	 *
	 * @param   string $type   The table type to instantiate. [optional]
	 * @param   string $prefix A prefix for the table class name. [optional]
	 * @param   array  $config Configuration array for model. [optional]
	 *
	 * @return  JTable  A database object
	 *
	 * @since   1.6
	 */
	public function getTable($type = 'Document', $prefix = 'FiredriveTable', $config = array())
	{
		return JTable::getInstance($type, $prefix, $config);
	}

	/**
	 * Batch copy items to a new category or current.
	 *
	 * @param   integer $value    The new category.
	 * @param   array   $pks      An array of row IDs.
	 * @param   array   $contexts An array of item contexts.
	 *
	 * @return  mixed  An array of new IDs on success, boolean false on failure.
	 *
	 * @since    2.5
	 */
	protected function batchCopy($value, $pks, $contexts)
	{
		$categoryId = (int) $value;

		/** @var FiredriveTableDocument $table */
		$table  = $this->getTable();
		$newIds = array();

		// Check that the category exists
		if ($categoryId)
		{
			$categoryTable = JTable::getInstance('Category');

			if (!$categoryTable->load($categoryId))
			{
				if ($error = $categoryTable->getError())
				{
					// Fatal error
					$this->setError($error);

					return false;
				}

				$this->setError(JText::_('JLIB_APPLICATION_ERROR_BATCH_MOVE_CATEGORY_NOT_FOUND'));

				return false;
			}
		}

		if (empty($categoryId))
		{
			$this->setError(JText::_('JLIB_APPLICATION_ERROR_BATCH_MOVE_CATEGORY_NOT_FOUND'));

			return false;
		}

		// Check that the user has create permission for the component
		if (!JFactory::getUser()->authorise('core.create', 'com_firedrive.category.' . $categoryId))
		{
			$this->setError(JText::_('JLIB_APPLICATION_ERROR_BATCH_CANNOT_CREATE'));

			return false;
		}

		// Parent exists so we let's proceed
		while (!empty($pks))
		{
			// Pop the first ID off the stack
			$pk = array_shift($pks);

			$table->reset();

			// Check that the row actually exists
			if (!$table->load($pk))
			{
				if ($error = $table->getError())
				{
					// Fatal error
					$this->setError($error);

					return false;
				}

				// Not fatal error
				$this->setError(JText::sprintf('JLIB_APPLICATION_ERROR_BATCH_MOVE_ROW_NOT_FOUND', $pk));
				continue;
			}

			// Alter the title & alias
			$data         = $this->generateNewTitle($categoryId, $table->alias, $table->title);
			$table->title = $data['0'];
			$table->alias = $data['1'];

			// Reset the ID because we are making a copy
			$table->id = 0;

			// New category ID
			$table->catid = $categoryId;

			// New file path
			$copy = FiredriveHelper::copyFile($table->file_name);
			if ($copy === false)
			{
				throw new Exception(JText::sprintf('COM_FIREDRIVE_FIELD_COPY_ERROR', $this->form["file_name"]), 500);
			}
			$table->file_name = $copy;

			// Unpublish because we are making a copy
			$table->state = 0;

			// TODO: Deal with ordering?
			// $table->ordering = 1;
			// Check the row.
			if (!$table->check())
			{
				$this->setError($table->getError());

				return false;
			}

			// Store the row.
			if (!$table->store())
			{
				$this->setError($table->getError());

				return false;
			}

			// Get the new item ID
			$newId = $table->get('id');

			// Add the new ID to the array
			$newIds[$pk] = $newId;
		}

		// Clean the cache
		$this->cleanCache();

		return $newIds;
	}

	/**
	 * Method to get the data that should be injected in the form.
	 *
	 * @return  mixed  The data for the form.
	 *
	 * @since 5.2.1
	 */
	protected function loadFormData()
	{
		// Check the session for previously entered form data.
		$app  = JFactory::getApplication();
		$data = $app->getUserState('com_firedrive.edit.document.data', array());

		if (empty($data))
		{
			$data = $this->getItem();

			// Prime some default values.
			if ($this->getState('document.id') == 0)
			{
				$filters     = (array) $app->getUserState('com_firedrive.document.filter');
				$filterCatId = isset($filters['category_id']) ? $filters['category_id'] : null;

				$data->set('catid', $app->input->getInt('catid', $filterCatId));
			}
		}

		$this->preprocessData('com_firedrive.document', $data);

		return $data;
	}

	/**
	 * Method to get a single record.
	 *
	 * @param   integer $pk The id of the primary key.
	 *
	 * @return  \JObject|boolean  Object on success, false on failure.
	 *
	 * @since   5.2.1
	 */
	public function getItem($pk = null)
	{
		if ($item = parent::getItem($pk))
		{

			// TODO: Check why it breaks Joomla 4.0
			// Convert the metadata field to an array.
			$registry = new JRegistry;
			$registry->loadString($item->metadata);
			$item->metadata = $registry->toArray();

			$item->tags = new JHelperTags;
			$item->tags->getTagIds($item->id, 'com_firedrive.document');
			$item->metadata['tags'] = $item->tags;

			if ($item->id)
			{
				// Reserved users
				$db    = JFactory::getDbo();
				$query = $db->getQuery(true);
				$query->select('user_id');
				$query->from('#__firedrive_user_documents');
				$query->where('document_id = ' . $item->id);
				$db->setQuery($query);
				$item->reserved_user = $db->loadColumn();

				// Reserved groups
				$db    = JFactory::getDbo();
				$query = $db->getQuery(true);
				$query->select('group_id');
				$query->from('#__firedrive_group_documents');
				$query->where('document_id = ' . $item->id);
				$db->setQuery($query);
				$item->reserved_group = $db->loadColumn();
			}
		}

		return $item;
	}

	/**
	 * A protected method to get a set of ordering conditions.
	 *
	 * @param   JTable $table A record object.
	 *
	 * @return  array  An array of conditions to add to add to ordering queries.
	 *
	 * @since   1.6
	 */
	protected function getReorderConditions($table)
	{
		return array(
			'catid = ' . (int) $table->catid,
			'state >= 0'
		);
	}

	/**
	 * Prepare and sanitise the table prior to saving.
	 *
	 * @param   JTable $table A JTable object.
	 *
	 * @return  void
	 *
	 * @since 5.2.1
	 */
	protected function prepareTable($table)
	{
		$table->title = htmlspecialchars_decode($table->title, ENT_QUOTES);

		$date = JFactory::getDate();
		$user = JFactory::getUser();

		if (empty($table->id))
		{
			// Set the values
			$table->created       = $date->toSql();
			$table->created_by    = $user->id;
			$table->download_last = null;

			// Set ordering to the last item if not set
			if (empty($table->ordering))
			{
				$db    = $this->getDbo();
				$query = $db->getQuery(true)
					->select('MAX(ordering)')
					->from('#__firedrive');

				$db->setQuery($query);
				$max = $db->loadResult();

				$table->ordering = $max + 1;
			}
		}
		else
		{
			// Set the values
			$table->modified    = $date->toSql();
			$table->modified_by = $user->id;
		}
	}

	/**
	 * Allows preprocessing of the JForm object.
	 *
	 * @param   JForm  $form  The form object
	 * @param   array  $data  The data to be merged into the form object
	 * @param   string $group The plugin group to be executed
	 *
	 * @return  void
	 *
	 * @since    3.6.1
	 * @throws  \Exception if there is an error in the form event.
	 */
	protected function preprocessForm(JForm $form, $data, $group = 'content')
	{
		if ($this->canCreateCategory())
		{
			$form->setFieldAttribute('catid', 'allowAdd', 'true');
		}

		parent::preprocessForm($form, $data, $group);
	}

	/**
	 * Is the user allowed to create an on the fly category?
	 *
	 * @return  bool
	 *
	 * @since   3.6.1
	 */
	private function canCreateCategory()
	{
		return JFactory::getUser()->authorise('core.create', 'com_firedrive');
	}

}
