<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\Twig\NodeVisitor;

use Symfony\Bridge\Twig\Node\TransDefaultDomainNode;
use Symfony\Bridge\Twig\Node\TransNode;
use Twig\Environment;
use Twig\Node\BlockNode;
use Twig\Node\EmptyNode;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\AssignNameExpression;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\Expression\Variable\LocalVariable;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\Nodes;
use Twig\Node\SetNode;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class TranslationDefaultDomainNodeVisitor implements NodeVisitorInterface
{
    private Scope $scope;
    private int $nestingLevel = 0;

    public function __construct()
    {
        $this->scope = new Scope();
    }

    public function enterNode(Node $node, Environment $env): Node
    {
        if ($node instanceof BlockNode || $node instanceof ModuleNode) {
            $this->scope = $this->scope->enter();
        }

        if ($node instanceof TransDefaultDomainNode) {
            ++$this->nestingLevel;

            if ($node->getNode('expr') instanceof ConstantExpression) {
                $this->scope->set('domain', $node->getNode('expr'));

                return $node;
            }

            if (class_exists(Nodes::class)) {
                $name = new LocalVariable(null, $node->getTemplateLine());
                $this->scope->set('domain', $name);

                return new SetNode(false, new Nodes([$name]), new Nodes([$node->getNode('expr')]), $node->getTemplateLine());
            }

            $var = '__internal_trans_default_domain_'.$this->nestingLevel;
            $name = new AssignNameExpression($var, $node->getTemplateLine());
            $this->scope->set('domain', new NameExpression($var, $node->getTemplateLine()));

            return new SetNode(false, new Node([$name]), new Node([$node->getNode('expr')]), $node->getTemplateLine());
        }

        if (!$this->scope->has('domain')) {
            return $node;
        }

        if ($node instanceof FilterExpression && 'trans' === ($node->hasAttribute('twig_callable') ? $node->getAttribute('twig_callable')->getName() : $node->getNode('filter')->getAttribute('value'))) {
            $arguments = $node->getNode('arguments');

            if ($arguments instanceof EmptyNode) {
                $arguments = new Nodes();
                $node->setNode('arguments', $arguments);
            }

            if ($this->isNamedArguments($arguments)) {
                if (!$arguments->hasNode('domain') && !$arguments->hasNode(1)) {
                    $arguments->setNode('domain', $this->scope->get('domain'));
                }
            } elseif (!$arguments->hasNode(1)) {
                if (!$arguments->hasNode(0)) {
                    $arguments->setNode(0, new ArrayExpression([], $node->getTemplateLine()));
                }

                $arguments->setNode(1, $this->scope->get('domain'));
            }
        } elseif ($node instanceof TransNode) {
            if (!$node->hasNode('domain')) {
                $node->setNode('domain', $this->scope->get('domain'));
            }
        }

        return $node;
    }

    public function leaveNode(Node $node, Environment $env): ?Node
    {
        if ($node instanceof TransDefaultDomainNode) {
            --$this->nestingLevel;

            return null;
        }

        if ($node instanceof BlockNode || $node instanceof ModuleNode) {
            $this->scope = $this->scope->leave();
        }

        return $node;
    }

    public function getPriority(): int
    {
        return -10;
    }

    private function isNamedArguments(Node $arguments): bool
    {
        foreach ($arguments as $name => $node) {
            if (!\is_int($name)) {
                return true;
            }
        }

        return false;
    }
}
