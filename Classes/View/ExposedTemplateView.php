<?php
namespace FluidTYPO3\Flux\View;

/*
 * This file is part of the FluidTYPO3/Flux project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use FluidTYPO3\Flux\Form;
use FluidTYPO3\Flux\Form\Container\Grid;
use FluidTYPO3\Flux\Service\FluxService;
use FluidTYPO3\Flux\Utility\ExtensionNamingUtility;
use FluidTYPO3\Flux\Utility\ResolveUtility;
use FluidTYPO3\Flux\ViewHelpers\AbstractFormViewHelper;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ControllerContext;
use TYPO3\CMS\Extbase\Mvc\View\ViewInterface;
use TYPO3\CMS\Fluid\Core\Parser\ParsedTemplateInterface;
use TYPO3\CMS\Fluid\View\AbstractTemplateView;
use TYPO3\CMS\Fluid\View\TemplateView;

/**
 * ExposedTemplateView. Allows access to registered template and viewhelper
 * variables from a Fluid template.
 */
class ExposedTemplateView extends TemplateView implements ViewInterface {

	/**
	 * @var FluxService
	 */
	protected $configurationService;

	/**
	 * @var array
	 */
	protected $renderedSections = array();

	/**
	 * @param FluxService $configurationService
	 * @return void
	 */
	public function injectConfigurationService(FluxService $configurationService) {
		$this->configurationService = $configurationService;
	}

	/**
	 * @param TemplatePaths $templatePaths
	 * @return void
	 */
	public function setTemplatePaths(TemplatePaths $templatePaths) {
		$this->setTemplateRootPaths($templatePaths->getTemplateRootPaths());
		$this->setLayoutRootPaths($templatePaths->getLayoutRootPaths());
		$this->setPartialRootPaths($templatePaths->getPartialRootPaths());
	}

	/**
	 * @param string $sectionName
	 * @param string $formName
	 * @return Form|NULL
	 */
	public function getForm($sectionName = 'Configuration', $formName = 'form') {
		/** @var Form $form */
		$form = $this->getStoredVariable(AbstractFormViewHelper::SCOPE, $formName, $sectionName);
		if (NULL !== $form && TRUE === isset($this->templatePathAndFilename)) {
			$form->setOption(Form::OPTION_TEMPLATEFILE, $this->templatePathAndFilename);
			$signature = ExtensionNamingUtility::getExtensionSignature($this->controllerContext->getRequest()->getControllerExtensionName());
			$overrides = (array) $this->configurationService->getTypoScriptByPath('plugin.tx_' . $signature . '.forms.' . $form->getName());
			$form->modify($overrides);
		}
		return $form;
	}

	/**
	 * @param string $sectionName
	 * @param string $gridName
	 * @return Grid
	 */
	public function getGrid($sectionName = 'Configuration', $gridName = 'grid') {
		/** @var Grid[] $grids */
		/** @var Grid $grid */
		$grids = $this->getStoredVariable(AbstractFormViewHelper::SCOPE, 'grids', $sectionName);
		$grid = NULL;
		if (TRUE === isset($grids[$gridName])) {
			$grid = $grids[$gridName];
		}
		return $grid;
	}

	/**
	 * Get a variable stored in the Fluid template
	 * @param string $viewHelperClassName Class name of the ViewHelper which stored the variable
	 * @param string $name Name of the variable which the ViewHelper stored
	 * @param string $sectionName Optional name of a section in which the ViewHelper was called
	 * @return mixed
	 * @throws \RuntimeException
	 */
	protected function getStoredVariable($viewHelperClassName, $name, $sectionName = NULL) {
		if (TRUE === isset($this->renderedSections[$sectionName])) {
			return $this->renderedSections[$sectionName]->get($viewHelperClassName, $name);
		}
		if (FALSE === $this->controllerContext instanceof ControllerContext) {
			throw new \RuntimeException('ExposedTemplateView->getStoredVariable requires a ControllerContext, none exists (getStoredVariable method)', 1343521593);
		}
		$this->baseRenderingContext->setControllerContext($this->controllerContext);
		$this->templateParser->setConfiguration($this->buildParserConfiguration());
		$parsedTemplate = $this->getParsedTemplate();
		$this->startRendering(AbstractTemplateView::RENDERING_TEMPLATE, $parsedTemplate, $this->baseRenderingContext);
		$viewHelperVariableContainer = $this->baseRenderingContext->getViewHelperVariableContainer();
		if (FALSE === empty($sectionName)) {
			$this->renderStandaloneSection($sectionName, $this->baseRenderingContext->getTemplateVariableContainer()->getAll());
		} else {
			$this->render();
		}
		$this->stopRendering();
		if (FALSE === $viewHelperVariableContainer->exists($viewHelperClassName, $name)) {
			return NULL;
		}
		$this->renderedSections[$sectionName] = $viewHelperVariableContainer;
		$stored = $viewHelperVariableContainer->get($viewHelperClassName, $name);
		return $stored;
	}

	/**
	 * @return ParsedTemplateInterface
	 */
	public function getParsedTemplate() {
		$templateIdentifier = $this->getTemplateIdentifier();
		if (TRUE === $this->templateCompiler->has($templateIdentifier)) {
			$parsedTemplate = $this->templateCompiler->get($templateIdentifier);
		} else {
			$source = $this->getTemplateSource();
			$parsedTemplate = $this->templateParser->parse($source);
			if (TRUE === $parsedTemplate->isCompilable()) {
				$this->templateCompiler->store($templateIdentifier, $parsedTemplate);
			}
		}
		return $parsedTemplate;
	}

	/**
	 * Public-access wrapper for parent's method.
	 *
	 * @param string $actionName
	 * @return string
	 */
	public function getTemplatePathAndFilename($actionName = NULL) {
		return parent::getTemplatePathAndFilename($actionName);
	}

	/**
	 * Renders a section from the specified template w/o requring a call to the
	 * main render() method - allows for cherry-picking sections to render.
	 *
	 * @param string $sectionName
	 * @param array $variables
	 * @param boolean $optional
	 * @return string
	 */
	public function renderStandaloneSection($sectionName, $variables, $optional = TRUE) {
		$content = NULL;
		$this->baseRenderingContext->setControllerContext($this->controllerContext);
		$this->startRendering(AbstractTemplateView::RENDERING_TEMPLATE, $this->getParsedTemplate(), $this->baseRenderingContext);
		$content = parent::renderSection($sectionName, $variables, $optional);
		$this->stopRendering();
		return $content;
	}

	/**
	 * Wrapper method to make the static call to GeneralUtility mockable in tests
	 *
	 * @param string $pathAndFilename
	 *
	 * @return string absolute pathAndFilename
	 */
	protected function resolveFileNamePath($pathAndFilename) {
		return '/' !== $pathAndFilename{0} ? GeneralUtility::getFileAbsFileName($pathAndFilename) : $pathAndFilename;
	}
}
