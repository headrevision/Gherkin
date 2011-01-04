<?php

namespace Behat\Gherkin;

use Behat\Gherkin\Exception\Exception,
    Behat\Gherkin\Keywords\KeywordsInterface;

class Lexer
{
    protected $input;
    protected $keywords;
    protected $line             = 1;
    protected $deferredObjects  = array();
    protected $stash            = array();
    protected $inPyString       = false;
    protected $lastIndentString = '';

    /**
     * Initialize Lexer.
     *
     * @param   KeywordsInterface   $keywords   keywords holder
     */
    public function __construct(KeywordsInterface $keywords)
    {
        $this->keywords = $keywords;
    }

    /**
     * Set lexer input.
     * 
     * @param   string  $input  input string
     */
    public function setInput($input)
    {
        $this->input            = preg_replace(array('/\r\n|\r/', '/\t/'), array("\n", '  '), $input);
        $this->line             = 1;
        $this->deferredObjects  = array();
        $this->stash            = array();
        $this->inPyString       = false;
        $this->lastIndentString = '';
    }

    /**
     * Set keywords language.
     *
     * @param   string  $language
     */
    public function setLanguage($language)
    {
        $this->keywords->setLanguage($language);
    }

    /**
     * Return next token or previously stashed one.
     * 
     * @return  Object
     */
    public function getAdvancedToken()
    {
        if ($token = $this->getStashedToken()) {
            return $token;
        }

        return $this->getNextToken();
    }

    /**
     * Return current line number.
     * 
     * @return  integer
     */
    public function getCurrentLine()
    {
        return $this->line;
    }

    /**
     * Defer token.
     * 
     * @param   Object   $token  token to defer
     */
    public function deferToken(\stdClass $token)
    {
        $this->deferredObjects[] = $token;
    }

    /**
     * Predict for number of tokens.
     * 
     * @param   integer     $number number of tokens to predict
     *
     * @return  Object              predicted token
     */
    public function predictToken($number = 1)
    {
        $fetch = $number - count($this->stash);

        while ($fetch-- > 0) {
            $this->stash[] = $this->getNextToken();
        }

        return $this->stash[--$number];
    }

    /**
     * Construct token with specified parameters.
     * 
     * @param   string  $type   token type
     * @param   string  $value  token value
     *
     * @return  Object          new token object
     */
    public function takeToken($type, $value = null)
    {
        return (Object) array(
            'type'  => $type,
            'line'  => $this->line,
            'value' => $value ?: null
        );
    }

    /**
     * Return stashed token.
     * 
     * @return  Object|boolean   token if has stashed, false otherways
     */
    protected function getStashedToken()
    {
        return count($this->stash) ? array_shift($this->stash) : null;
    }

    /**
     * Return deferred token.
     * 
     * @return  Object|boolean   token if has deferred, false otherways
     */
    protected function getDeferredToken()
    {
        return count($this->deferredObjects) ? array_shift($this->deferredObjects) : null;
    }

    /**
     * Return next token.
     * 
     * @return  Object
     */
    protected function getNextToken()
    {
        return $this->getDeferredToken()
            ?: $this->scanEOS()
            ?: $this->scanPyStringOperator()
            ?: $this->scanPyStringContent()
            ?: $this->scanTableRow()
            ?: $this->scanFeature()
            ?: $this->scanBackground()
            ?: $this->scanScenario()
            ?: $this->scanOutline()
            ?: $this->scanExamples()
            ?: $this->scanStep()
            ?: $this->scanNewline()
            ?: $this->scanLanguage()
            ?: $this->scanComment()
            ?: $this->scanTags()
            ?: $this->scanText();
    }

    /**
     * Consume input.
     * 
     * @param   integer $length length of input to consume
     */
    protected function consumeInput($length)
    {
        $this->input = mb_substr($this->input, $length);
    }

    /**
     * Scan for token with specified regex.
     * 
     * @param   string  $regex  regular expression
     * @param   string  $type   expected token type
     *
     * @return  Object|null
     */
    protected function scanInput($regex, $type)
    {
        $matches = array();
        if (preg_match($regex, $this->input, $matches)) {
            $this->consumeInput(mb_strlen($matches[0]));

            return $this->takeToken($type, $matches[1]);
        }
    }

    /**
     * Scan EOS from input & return it if found.
     * 
     * @return  Object|null
     */
    protected function scanEOS()
    {
        if (mb_strlen($this->input)) {
            return;
        }

        return $this->takeToken('EOS');
    }

    /**
     * Scan Feature from input & return it if found.
     * 
     * @return  Object|null
     */
    protected function scanFeature()
    {
        return $this->scanInput('/^' . $this->keywords->getFeatureKeyword() . '\: *([^\n]*)/', 'Feature');
    }

    /**
     * Scan Background from input & return it if found.
     *
     * @return  Object|null
     */
    protected function scanBackground()
    {
        return $this->scanInput('/^' . $this->keywords->getBackgroundKeyword() . '\: *([^\n]*)/', 'Background');
    }

    /**
     * Scan Scenario from input & return it if found.
     *
     * @return  Object|null
     */
    protected function scanScenario()
    {
        return $this->scanInput('/^' . $this->keywords->getScenarioKeyword() . '\: *([^\n]*)/', 'Scenario');
    }

    /**
     * Scan Scenario Outline from input & return it if found.
     *
     * @return  Object|null
     */
    protected function scanOutline()
    {
        return $this->scanInput('/^' . $this->keywords->getOutlineKeyword() . '\: *([^\n]*)/', 'Outline');
    }

    /**
     * Scan Scenario Outline Examples from input & return it if found.
     *
     * @return  Object|null
     */
    protected function scanExamples()
    {
        return $this->scanInput('/^' . $this->keywords->getExamplesKeyword() . '\: *([^\n]*)/', 'Examples');
    }

    /**
     * Scan Step from input & return it if found.
     *
     * @return  Object|null
     */
    protected function scanStep()
    {
        $matches  = array();
        $keywords = $this->keywords->getStepKeywords();

        if (preg_match('/^(' . implode('|', $keywords) . ') +([^\n]+)/', $this->input, $matches)) {
            $this->consumeInput(mb_strlen($matches[0]));
            $token = $this->takeToken('Step', $matches[1]);
            $token->text = $matches[2];

            return $token;
        }
    }

    /**
     * Scan PyString from input & return it if found.
     *
     * @return  Object|null
     */
    protected function scanPyStringOperator()
    {
        $matches = array();

        if (preg_match('/^"""[^\n]*/', $this->input, $matches)) {
            $this->consumeInput(mb_strlen($matches[0]));
            $this->inPyString =! $this->inPyString;

            $token = $this->takeToken('PyStringOperator');
            $token->swallow = mb_strlen($this->lastIndentString);

            return $token;
        }
    }

    /**
     * Scan PyString content.
     *
     * @return  Object|null
     */
    protected function scanPyStringContent()
    {
        if ($this->inPyString) {
            $matches = array();
            if (preg_match('/^([^\n]+)/', $this->input, $matches)) {
                $this->consumeInput(mb_strlen($matches[0]));

                return $this->takeToken('Text', $this->lastIndentString . $matches[1]);
            }
        }
    }

    /**
     * Scan Table Row from input & return it if found.
     *
     * @return  Object|null
     */
    protected function scanTableRow()
    {
        $matches = array();

        if (preg_match('/^\|([^\n]+)\|/', $this->input, $matches)) {
            $this->consumeInput(mb_strlen($matches[0]));
            $token = $this->takeToken('TableRow');

            // Split & trim row columns
            $columns = explode('|', $matches[1]);
            $columns = array_map(function($column) {
                return trim($column);
            }, $columns);
            $token->columns = $columns;

            return $token;
        }
    }

    /**
     * Scan Newline from input & return it if found.
     *
     * @return  Object|null
     */
    protected function scanNewline()
    {
        $matches = array();

        if (preg_match('/^\n( *)/', $this->input, $matches)) {
            $this->line++;
            $this->lastIndentString = $matches[1];

            $this->consumeInput(mb_strlen($matches[0]));
            $token = $this->takeToken('Newline');

            return $token;
        }
    }

    /**
     * Scan Tags from input & return it if found.
     *
     * @return  Object|null
     */
    protected function scanTags()
    {
        $matches = array();

        if (preg_match('/^@([^\n]+)/', $this->input, $matches)) {
            $this->consumeInput(mb_strlen($matches[0]));
            $token = $this->takeToken('Tag');

            $tags = explode('@', $matches[1]);
            $tags = array_map(function($tag){
                return trim($tag);
            }, $tags);
            $token->tags = $tags;

            return $token;
        }
    }

    /**
     * Scan Language specifier from input & return it if found.
     *
     * @return  Object|null
     */
    protected function scanLanguage()
    {
        return $this->scanInput('/^\# *language: *([\w_\-]+)/', 'Language');
    }

    /**
     * Scan Comment from input & return it if found.
     *
     * @return  Object|null
     */
    protected function scanComment()
    {
        return $this->scanInput('/^\#([^\n]*)/', 'Comment');
    }

    /**
     * Scan text from input & return it if found. 
     * 
     * @return  Object|null
     */
    protected function scanText()
    {
        return $this->scanInput('/^([^\n\#]+)/', 'Text');
    }
}
