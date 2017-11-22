<?php namespace App\Http\Controllers;

Use App\Word;
Use App\Sign;

class IndexController extends Controller {

	/**
	 * Show the main index 
     * 
     * @link www.wign.dk
	 * @return View
	 */
	public function index() {
		$randomWord = Word::has('signs')->random()->first();
        $signCount = Sign::count();
        $wordCount = Word::has('signs')->count();
		return view('index')->with(['randomWord' => $randomWord, 'signCount' => $signCount, 'wordCount' => $wordCount]);
	}

    /**
     * Show the about page
     * 
     * @link www.wign.dk/om
     * @return View
     */
    public function about() {
        return view('about');
    }

    /**
     * Show the help page
     * 
     * @link www.wign.dk/help
     * @return View
     */
    public function help() {
        return view('help');
    }

    /**
     * Show the policy page
     * 
     * @link www.wign.dk/retningslinjer
     * @return View
     */
    public function policy() {
        return view('policy');
    }

    /**
     * Show the "fuck you" page
     * 
     * @return View
     */
    public function blacklist() {
        return view('blacklist');
    }

}
