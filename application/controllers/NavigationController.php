<?php
/* Icinga Web 2 | (c) 2013-2015 Icinga Development Team | GPLv2+ */

namespace Icinga\Controllers;

use Exception;
use Icinga\Application\Config;
use Icinga\Exception\NotFoundError;
use Icinga\Data\DataArray\ArrayDatasource;
use Icinga\Forms\ConfirmRemovalForm;
use Icinga\Forms\Navigation\NavigationConfigForm;
use Icinga\Web\Controller;
use Icinga\Web\Form;
use Icinga\Web\Navigation\Navigation;
use Icinga\Web\Notification;
use Icinga\Web\Url;

/**
 * Navigation configuration
 */
class NavigationController extends Controller
{
    /**
     * The global navigation item type configuration
     *
     * @var array
     */
    protected $itemTypeConfig;

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        parent::init();
        $this->itemTypeConfig = Navigation::getItemTypeConfiguration();
    }

    /**
     * Return the label for the given navigation item type
     *
     * @param   string  $type
     *
     * @return  string          $type if no label can be found
     */
    protected function getItemLabel($type)
    {
        return isset($this->itemTypeConfig[$type]['label']) ? $this->itemTypeConfig[$type]['label'] : $type;
    }

    /**
     * Return a list of available navigation item types
     *
     * @return  array
     */
    protected function listItemTypes()
    {
        $types = array();
        foreach ($this->itemTypeConfig as $type => $options) {
            $types[$type] = isset($options['label']) ? $options['label'] : $type;
        }

        return $types;
    }

    /**
     * Return the path to the configuration file for the given navigation item type and user
     *
     * @param   string  $type
     * @param   string  $username
     *
     * @return  string
     *
     * @throws  IcingaException     In case the given type is unknown
     */
    protected function getConfigPath($type, $username = null)
    {
        if (isset($this->moduleItemTypes[$type])) {
            $options = $this->moduleItemTypes[$type];
        } elseif (isset($this->defaultItemTypes[$type])) {
            $options = $this->defaultItemTypes[$type];
        } else {
            throw new IcingaException('Invalid navigation item type %s provided', $type);
        }

        if (isset($options['config'])) {
            $filename = $options['config'] . '.ini';
        } else {
            $filename = $type . 's.ini';
        }

        return Config::resolvePath(($username ? "preferences/$username/" : 'navigation/') . $filename);
    }

    /**
     * Show the current user a list of his/her navigation items
     */
    public function indexAction()
    {
        $user = $this->Auth()->getUser();

        $ds = new ArrayDatasource(array_merge(
            Config::app('navigation')->select()->where('owner', $user->getUsername())->fetchAll(),
            iterator_to_array($user->loadNavigationConfig())
        ));
        $ds->setKeyColumn('name');
        $query = $ds->select();

        $this->view->types = $this->listItemTypes();
        $this->view->items = $query;

        $this->getTabs()->add(
            'navigation',
            array(
                'title'     => $this->translate('List and configure your own navigation items'),
                'label'     => $this->translate('Navigation'),
                'url'       => 'navigation'
            )
        )->activate('navigation');
        $this->setupSortControl(
            array(
                'type'  => $this->translate('Type'),
                'owner' => $this->translate('Shared'),
                'name'  => $this->translate('Shared Navigation')
            ),
            $query
        );
    }

    /**
     * List all shared navigation items
     */
    public function sharedAction()
    {
        $this->assertPermission('config/application/navigation');
        $config = Config::app('navigation');
        $config->getConfigObject()->setKeyColumn('name');
        $query = $config->select();

        $removeForm = new Form();
        $removeForm->setUidDisabled();
        $removeForm->addElement('hidden', 'name', array(
            'decorators'    => array('ViewHelper')
        ));
        $removeForm->addElement('hidden', 'redirect', array(
            'value'         => Url::fromPath('navigation/shared'),
            'decorators'    => array('ViewHelper')
        ));
        $removeForm->addElement('button', 'btn_submit', array(
            'escape'        => false,
            'type'          => 'submit',
            'class'         => 'link-like spinner',
            'value'         => 'btn_submit',
            'decorators'    => array('ViewHelper'),
            'label'         => $this->view->icon('trash'),
            'title'         => $this->translate('Unshare this navigation item')
        ));

        $this->view->removeForm = $removeForm;
        $this->view->types = $this->listItemTypes();
        $this->view->items = $query;

        $this->getTabs()->add(
            'navigation/shared',
            array(
                'title'     => $this->translate('List and configure shared navigation items'),
                'label'     => $this->translate('Shared Navigation'),
                'url'       => 'navigation/shared'
            )
        )->activate('navigation/shared');
        $this->setupSortControl(
            array(
                'type'  => $this->translate('Type'),
                'owner' => $this->translate('Owner'),
                'name'  => $this->translate('Shared Navigation')
            ),
            $query
        );
    }

    /**
     * Add a navigation item
     */
    public function addAction()
    {
        $form = new NavigationConfigForm();
        $form->setRedirectUrl('navigation');
        $form->setTitle($this->translate('Create New Navigation Item'));
        $form->setItemTypes(array_merge($this->defaultItemTypes, $this->moduleItemTypes));
        $form->addDescription($this->translate('Create a new navigation item, such as a menu entry or dashlet.'));
        $form->setUser($this->Auth()->getUser());
        $form->setOnSuccess(function (NavigationConfigForm $form) {
            $data = array_filter($form->getValues());

            try {
                $form->add($data);
            } catch (Exception $e) {
                $form->error($e->getMessage());
                return false;
            }

            if ($form->save()) {
                if ($data['type'] === 'menu-item') {
                    $form->getResponse()->setRerenderLayout();
                }

                Notification::success(t('Navigation item successfully created'));
                return true;
            }

            return false;
        });
        $form->handleRequest();

        $this->view->form = $form;
        $this->render('form');
    }

    /**
     * Edit a navigation item
     */
    public function editAction()
    {
        $itemName = $this->params->getRequired('name');
        $itemType = $this->params->getRequired('type');
        $referrer = $this->params->get('referrer', 'index');

        $user = $this->Auth()->getUser();
        if ($user->can('config/application/navigation')) {
            $itemOwner = $this->params->get('owner', $user->getUsername());
        } else {
            $itemOwner = $user->getUsername();
        }

        $form = new NavigationConfigForm();
        $form->setUser($user);
        $form->setShareConfig(Config::fromIni($this->getConfigPath($itemType)));
        $form->setUserConfig(Config::fromIni($this->getConfigPath($itemType, $itemOwner)));
        $form->setRedirectUrl($referrer === 'shared' ? 'navigation/shared' : 'navigation');
        $form->setTitle(sprintf($this->translate('Edit %s %s'), $this->getItemLabel($itemType), $itemName));
        $form->setOnSuccess(function (NavigationConfigForm $form) use ($itemName) {
            $data = array_map(
                function ($v) {
                    return $v !== '' ? $v : null;
                },
                $form->getValues()
            );

            try {
                $form->edit($itemName, $data);
            } catch (NotFoundError $e) {
                throw $e;
            } catch (Exception $e) {
                $form->error($e->getMessage());
                return false;
            }

            if ($form->save()) {
                if (isset($data['type']) && $data['type'] === 'menu-item') {
                    $form->getResponse()->setRerenderLayout();
                }

                Notification::success(sprintf(t('Navigation item "%s" successfully updated'), $itemName));
                return true;
            }

            return false;
        });

        try {
            $form->load($itemName);
            $form->handleRequest();
        } catch (NotFoundError $_) {
            $this->httpNotFound(sprintf($this->translate('Navigation item "%s" not found'), $itemName));
        }

        $this->view->form = $form;
        $this->render('form');
    }

    /**
     * Remove a navigation item
     */
    public function removeAction()
    {
        $itemName = $this->params->getRequired('name');
        $itemType = $this->params->getRequired('type');
        $user = $this->Auth()->getUser();

        $navigationConfigForm = new NavigationConfigForm();
        $navigationConfigForm->setUser($user);
        $navigationConfigForm->setShareConfig(Config::fromIni($this->getConfigPath($itemType)));
        $navigationConfigForm->setUserConfig(Config::fromIni($this->getConfigPath($itemType, $user->getUsername())));

        $form = new ConfirmRemovalForm();
        $form->setRedirectUrl('navigation');
        $form->setTitle(sprintf($this->translate('Remove %s %s'), $this->getItemLabel($itemType), $itemName));
        $form->setOnSuccess(function (ConfirmRemovalForm $form) use ($itemName, $navigationConfigForm) {
            try {
                $itemConfig = $navigationConfigForm->delete($itemName);
            } catch (NotFoundError $e) {
                Notification::success(sprintf(t('Navigation Item "%s" not found. No action required'), $itemName));
                return true;
            } catch (Exception $e) {
                $form->error($e->getMessage());
                return false;
            }

            if ($navigationConfigForm->save()) {
                if ($itemConfig->type === 'menu-item') {
                    $form->getResponse()->setRerenderLayout();
                }

                Notification::success(sprintf(t('Navigation Item "%s" successfully removed'), $itemName));
                return true;
            }

            return false;
        });
        $form->handleRequest();

        $this->view->form = $form;
        $this->render('form');
    }

    /**
     * Unshare a navigation item
     */
    public function unshareAction()
    {
        $this->assertPermission('config/application/navigation');
        $this->assertHttpMethod('POST');

        $itemType = $this->params->getRequired('type');
        $itemOwner = $this->params->getRequired('owner');

        $navigationConfigForm = new NavigationConfigForm();
        $navigationConfigForm->setUser($this->Auth()->getUser());
        $navigationConfigForm->setShareConfig(Config::fromIni($this->getConfigPath($itemType)));
        $navigationConfigForm->setUserConfig(Config::fromIni($this->getConfigPath($itemType, $itemOwner)));

        $form = new Form(array(
            'onSuccess' => function ($form) use ($navigationConfigForm) {
                $itemName = $form->getValue('name');

                try {
                    $newConfig = $navigationConfigForm->unshare($itemName);
                    if ($navigationConfigForm->save()) {
                        if ($newConfig->getSection($itemName)->type === 'menu-item') {
                            $form->getResponse()->setRerenderLayout();
                        }

                        Notification::success(sprintf(
                            t('Navigation item "%s" has been unshared'),
                            $form->getValue('name')
                        ));
                    } else {
                        // TODO: It failed obviously to write one of the configs, so we're leaving the user in
                        //       a inconsistent state. Luckily, it's nothing lost but possibly duplicated...
                        Notification::error(sprintf(
                            t('Failed to unshare navigation item "%s"'),
                            $form->getValue('name')
                        ));
                    }
                } catch (NotFoundError $e) {
                    throw $e;
                } catch (Exception $e) {
                    Notification::error($e->getMessage());
                }

                $redirect = $form->getValue('redirect');
                if (! empty($redirect)) {
                    $form->setRedirectUrl(htmlspecialchars_decode($redirect));
                }

                return true;
            }
        ));
        $form->setUidDisabled();
        $form->setSubmitLabel('btn_submit'); // Required to ensure that isSubmitted() is called
        $form->addElement('hidden', 'name', array('required' => true));
        $form->addElement('hidden', 'redirect');

        try {
            $form->handleRequest();
        } catch (NotFoundError $_) {
            $this->httpNotFound(sprintf($this->translate('Navigation item "%s" not found'), $form->getValue('name')));
        }
    }
}
