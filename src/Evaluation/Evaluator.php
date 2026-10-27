<?php

namespace Valtzu\OutgoingFirewall\Evaluation;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Valtzu\OutgoingFirewall\Rule\Action;
use Valtzu\OutgoingFirewall\Rule\Phase;
use Valtzu\OutgoingFirewall\Rule\Rule;
use Valtzu\OutgoingFirewall\Rule\RuleSet;

final class Evaluator
{
    public function __construct(
        private readonly RuleSet $ruleSet,
        private readonly ExpressionLanguage $language,
    ) {
    }

    public function ruleSet(): RuleSet
    {
        return $this->ruleSet;
    }

    /**
     * Returns the action of the first deciding rule, or null when no rule decided.
     */
    public function evaluate(Phase $phase, Context $context, Trace $trace): ?Action
    {
        return $this->run($this->ruleSet->rules, null, $phase, $context, $trace);
    }

    /**
     * @param list<Rule> $rules
     */
    private function run(array $rules, ?string $chain, Phase $phase, Context $context, Trace $trace): ?Action
    {
        foreach ($rules as $index => $rule) {
            if (!$rule->appliesTo($phase)) {
                continue;
            }

            $matched = (bool) $this->language->evaluate($rule->if, $context->expressionValues());
            $trace->record(new Step($phase, $chain, $index, $rule->if, $matched, $rule->describeEffect()));

            if (!$matched) {
                continue;
            }

            foreach ($rule->tag as $tag) {
                $context->addTag($tag);
            }

            foreach ($rule->jump as $name) {
                if (null !== $action = $this->run($this->ruleSet->chains[$name], $name, $phase, $context, $trace)) {
                    return $action;
                }
            }

            if (Action::Log === $rule->action) {
                $context->markLogged();
            } elseif (null !== $rule->action) {
                return $rule->action;
            }
        }

        return null;
    }
}
