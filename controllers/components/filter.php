<?php
/**
	CakePHP Filter Plugin

	Copyright (C) 2009-3827 dr. Hannibal Lecter / lecterror
	<http://lecterror.com/>

	Multi-licensed under:
		MPL <http://www.mozilla.org/MPL/MPL-1.1.html>
		LGPL <http://www.gnu.org/licenses/lgpl.html>
		GPL <http://www.gnu.org/licenses/gpl.html>
*/

/**
 * @property RequestHandlerComponent $RequestHandler
 * @property SessionComponent $Session
 */
class FilterComponent extends Object
{
	var $components = array('RequestHandler', 'Session');

	var $settings = array();
	var $nopersist = array();
	var $formData = array();

	function initialize(&$controller, $settings)
	{
		if (!isset($controller->filters))
		{
			return;
		}

		$this->__updatePersistence($controller, $settings);
		$this->settings[$controller->name] = $controller->filters;

		if (!isset($this->settings[$controller->name][$controller->action]))
		{
			return;
		}

		$settings = $this->settings[$controller->name][$controller->action];

		foreach ($settings as $model => $filter)
		{
			if (!isset($controller->$model))
			{
				trigger_error(sprintf(__('Filter model not found: %s', true), $model));
				continue;
			}

			$controller->$model->Behaviors->attach('Filter.Filtered', $filter);
		}
	}

	function startup(&$controller)
	{
		if (!isset($this->settings[$controller->name][$controller->action]))
		{
			return;
		}

		$settings = $this->settings[$controller->name][$controller->action];

		if (!in_array('Filter.Filter', $controller->helpers))
		{
			$controller->helpers[] = 'Filter.Filter';
		}

		$sessionKey = sprintf('FilterPlugin.Filters.%s.%s', $controller->name, $controller->action);

		if (!$this->RequestHandler->isPost() || !isset($controller->data['Filter']['filterFormId']))
		{
			$persistedData = array();

			if ($this->Session->check($sessionKey))
			{
				$persistedData = $this->Session->read($sessionKey);
			}
			
			if (empty($persistedData))
			{
				return;
			}

			$this->formData = $persistedData;
		}
		else
		{
			$this->formData = $controller->data;
			$this->Session->write($sessionKey, $this->formData);
		}

		foreach ($settings as $model => $options)
		{
			if (!isset($controller->$model))
			{
				trigger_error(sprintf(__('Filter model not found: %s', true), $model));
				continue;
			}

			$controller->$model->setFilterValues($this->formData);
		}
	}

	function beforeRender(&$controller)
	{
		if (!isset($this->settings[$controller->name][$controller->action]))
		{
			return;
		}

		$models = $this->settings[$controller->name][$controller->action];
		$viewFilterParams = array();

		foreach ($models as $model => $fields)
		{
			if (!isset($controller->$model))
			{
				trigger_error(sprintf(__('Filter model not found: %s', true), $model));
				continue;
			}

			foreach ($fields as $field => $settings)
			{
				if (!is_array($settings))
				{
					$field = $settings;
					$settings = array();
				}

				if (!isset($settings['required']))
				{
					$settings['required'] = false;
				}

				if (!isset($settings['type']))
				{
					$settings['type'] = 'text';
				}

				$options = array();

				if (!empty($this->formData))
				{
					$fieldName = $field;
					$fieldModel = $model;

					if (strpos($field, '.'))
					{
						list($fieldModel, $fieldName) = explode('.', $field);
					}

					if (isset($this->formData[$fieldModel][$fieldName]))
					{
						$options['value'] = $this->formData[$fieldModel][$fieldName];
					}
				}

				if (isset($settings['inputOptions']))
				{
					if (!is_array($settings['inputOptions']))
					{
						$settings['inputOptions'] = array($settings['inputOptions']);
					}

					$options = array_merge($options, $settings['inputOptions']);
				}

				if (isset($settings['label']))
				{
					$options['label'] = $settings['label'];
				}

				switch ($settings['type'])
				{
					case 'text':
						$options['type'] = 'text';
						break;

					case 'select':
						$options['type'] = 'select';

						$fieldModel = $model;

						if (strpos($field, '.') !== false)
						{
							list($fieldModel, $fieldName) = explode('.', $field);
						}

						$workingModel =& ClassRegistry::init($fieldModel);

						$selectOptions = array();

						if (isset($settings['selectOptions']))
						{
							$selectOptions = $settings['selectOptions'];
						}

						if (isset($settings['selector']))
						{
							if (!method_exists($workingModel, $settings['selector']))
							{
								trigger_error(sprintf(__('Selector method "%s" not found in model "%s" for field "%s"!', true), $settings['selector'], $fieldModel, $fieldName));
								return;
							}

							$selectorName = $settings['selector'];
							$options['options'] = $workingModel->$selectorName($selectOptions);
						}
						else
						{
							$options['options'] = $workingModel->find('list', array_merge($selectOptions, array('nofilter' => true)));
						}

						if (!$settings['required'])
						{
							$options['empty'] = '';
						}
						break;

					case 'checkbox':
						$options['type'] = 'checkbox';

						if (isset($options['value']))
						{
							$options['checked'] = !!$options['value'];
							unset($options['value']);
						}
						else if (isset($settings['default']))
						{
							$options['checked'] = !!$settings['default'];
						}
						break;

					default:
						continue;
				}

				// if no value has been set, show the default one
				if(!isset($options['value']))
				{
					$options['value'] = $settings['default'];
				}

				$viewFilterParams[] = array
				(
					'name' => $field,
					'options' => $options
				);
			}
		}

		$controller->set('viewFilterParams', $viewFilterParams);
	}

	function __updatePersistence(&$controller, $settings)
	{
		if ($this->Session->check('FilterPlugin.NoPersist'))
		{
			$this->nopersist = $this->Session->read('FilterPlugin.NoPersist');
		}

		if (isset($settings['nopersist']))
		{
			$this->nopersist[$controller->name] = $settings['nopersist'];
			$this->Session->write('FilterPlugin.NoPersist', $this->nopersist);
		}
		else if (isset($this->nopersist[$controller->name]))
		{
			unset($this->nopersist[$controller->name]);
			$this->Session->write('FilterPlugin.NoPersist', $this->nopersist);
		}

		if (!empty($this->nopersist))
		{
			foreach ($this->nopersist as $nopersistController => $actions)
			{
				if (is_string($actions))
				{
					$actions = array($actions);
				}
				else if (is_bool($actions) && $actions == true)
				{
					$actions = array();
				}

				if (empty($actions) && $controller->name != $nopersistController)
				{
					if ($this->Session->check(sprintf('FilterPlugin.Filters.%s', $nopersistController)))
					{
						$this->Session->del(sprintf('FilterPlugin.Filters.%s', $nopersistController));
						continue;
					}
				}

				foreach ($actions as $action)
				{
					if ($controller->name == $nopersistController && $action == $controller->action)
					{
						continue;
					}

					if ($this->Session->check(sprintf('FilterPlugin.Filters.%s.%s', $nopersistController, $action)))
					{
						$this->Session->del(sprintf('FilterPlugin.Filters.%s.%s', $nopersistController, $action));
					}
				}
			}
		}
	}
}
