<?php

class Template {
  private $twig;

  public function __construct() {
    $loader = new Twig_Loader_String();
    $this->twig = new Twig_Environment($loader,array(
        'debug' => true
    ));
    $this->twig->addExtension(new Twig_Extension_Debug());
  }

    public function render($template, $attr) {
        try {
            $tpl = $this->twig->loadTemplate($template);
            return $tpl->render($attr);
        } catch (Twig_Error_Loader $e) {
            echo "Twig Error\n";
            var_dump($e->getMessage());
            return false;
        } catch (Twig_Error_Syntax $e) {
            echo "Twig Error\n";
            var_dump($e->getMessage());
            return false;
        }
    }
}

?>
