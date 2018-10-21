<?php

namespace App\Http\Controllers;

use App\Il;
use Illuminate\Http\Request;

use App\Description;
use App\Video;
use App\Word;
use App\Post;
use App\Tag;

use Illuminate\Support\Facades\Auth;
use URL;
use App\Helpers\Helper;

define( 'REGEXP', config( 'wign.tagRegexp' ) );

class PostController extends Controller
{

    /**
     * Display the "create a post" view with the relevant data attached.
     * If a word is set, it's checked if it already has a post to it.
     *
     * @param String $word the queried word. Nullable.
     *
     * @return \Illuminate\View\View of "create a post"
     */
    public function getPostIndex( $word = null ) {
        if ( empty( $word ) ) {
            return view( 'create' );
        }

        $wordData        = Word::find($word);
        $data['hasPost'] = empty( $wordData ) ? 0 : 1;
        $data['word']    = empty( $wordData ) ? $word : $wordData->word;

        return view( 'create' )->with( $data );
    }

    public function postNewPost( Request $request ) {
        // Validating the incoming request
        $this->validate($request, [
            'word'              => 'required|string',
            'description'       => 'nullable|string',
            'wign01_uuid'       => 'required',
            'wign01_vga_mp4'    => 'required',
            'wign01_vga_thumb'  => 'required',
            'wign01_qvga_thumb' => 'required',
        ] );

        $user = Auth::user();

        $post = new Post([
            'user_id' => $user->id
        ]);
        $post->save();
        $il = new Il([
            'rank' => $request->input('IlRank') === null ? 1 : $request->input('IlRank')
        ]);
        $post->ils()->save($il);

        $word = Word::firstOrCreate( [ 'word' => $request->input('word') ] );
        $word->requests()->detach();
        $post->words()->attach($word->id, ['user_id' => $user->id]);

        $video = new Video([
            'user_id' => $user->id,
            'post_id' => $post->id,
            'playings' => 0, //Unnecessary?
            'camera_uuid'         => config('wign.cameratag.id'),
            'recorded_from'       => $request->input('recorded_from'),
            'video_uuid'          => $request->input('wign01_uuid'),
            'video_url'           => $request->input('wign01_vga_mp4'),
            'thumbnail_url'       => $request->input('wign01_vga_thumb'),
            'small_thumbnail_url' => $request->input('wign01_qvga_thumb')
        ]);
        $video->save();

        $desc = new Description([
            'user_id' => $user->id,
            'post_id' => $post->id,
            'text' => $request->input('description') === null ? "" : $request->input('description')
        ]);
        $desc->save();

        $desc->tags()->detach(); // Update the tag relations
        if ( !empty( $desc->description ) ) {
            preg_match_all( REGEXP, $desc, $hashtags ); //Store the unique tags in $hashtags
            if ( !empty( $hashtags ) ) {
                foreach ( $hashtags[1] as $hashtag ) {
                    $tag = Tag::firstOrCreate( [ 'tag' => $hashtag ] );
                    $desc->tags()->attach( $tag );
                }
            }
        }

        $flash['url'] = URL::to( config( 'wign.urlPath.create' ) );

        return redirect( config( 'wign.urlPath.sign' ) . '/' . $word->word )->with( $flash );
    }

    /**
     * Show the matched posts for this word page.
     * Display all the posts if $word is non-null and does exist in database
     * Otherwise show the 'no post' page
     *
     * @param string $word - a nullable string with the query $word
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\View\View
     */
    public function getPosts( $word ) {

        $word      = Helper::underscoreToSpace( $word );
        $wordModel = Word::whereWord( $word )->with('posts')->first();

        // If word exist in database
        if ( isset( $wordModel ) ) {
            $posts = $wordModel->posts()->get();
            foreach( $posts as $post)   {
                $content = self::replaceTagsToURL($post->currentDescription()->text);
                $post->descText = $content;
            }
            //$posts = Post::withCount('votes')->orderBy('votes_count', 'DESC')->orderBy('created_at')->get(['post', 'post_count']);
            return view( 'post' )->with( array( 'word' => $wordModel->word, 'posts' => $posts ) );
        }

        // If no word exist in database; make a list of suggested word and display the 'no sign' view.
        $suggestions = $this->getAlikeWords( $word, 5 );

        return view( 'nopost' )->with( compact([ 'word' , 'suggestions' ]) );
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id, Request $request)
    {
        $post = Post::find($id);
        $user = Auth::user();

        return null;
    }

    public function delete($id)
    {
        $post = Post::find($id);
        $video = Video::find('post_id', $id);
        $video->delete();
        $description = Description::find('post_id', $id);
        $description->delete();
        $post->delete();

        return redirect()->route('index')->with('info', 'Tegnet er fjernet.');
    }

    //////////////////////

    private static function replaceTagsToURL( string $text ): string {
        $replaceWith = '<a href="' . URL::to( config( "wign.urlPath.tags" ) ) . '/$1">$0</a>';
        $text        = preg_replace( REGEXP, $replaceWith, $text );

        return $text;
    }

    /**
     * Show the recent # words which have been assigned with a post
     *
     * @param int $number of recent results
     *
     * @return \Illuminate\View\View
     */
    public function showRecent( $number = 25 ) {
        $words = Word::has('posts')->latest( $number )->get();

        return view( 'list' )->with( compact([ 'words', 'number' ]) );
    }

    /**
     * Show all words with assigned post, sorted by word ASC
     *
     * @return \Illuminate\View\View
     */
    public function showAll() {
        $words = Word::withCount('posts')->orderBy('posts_count', 'DESC')->orderBy('word')->get(['word', 'posts_count']);

        return view( 'listAll' )->with( compact('words') );
    }

    /**
     * Searching for words that looks alike the queried $word
     * Current uses both "LIKE" mysql query and Levenshtein distance, and return $count words with the least distance to $word
     *
     * @param string $word
     *
     * @return array|null
     */
    private function getAlikeWords( string $word, int $count ) {
        $max_levenshtein = 5;
        $min_levenshtein = PHP_INT_MAX;
        $words           = Word::with('posts')->get();
        $tempArr         = array();

        foreach ( $words as $compareWord ) {
            $levenDist = levenshtein( strtolower( $word ), strtolower( $compareWord->word ) );
            if ( $levenDist > $max_levenshtein || $levenDist > $min_levenshtein ) {
                continue;
            } else {
                $tempArr[ $compareWord->word ] = $levenDist;
                if ( count( $tempArr ) == $count + 1 ) {
                    asort( $tempArr );
                    $min_levenshtein = array_pop( $tempArr );
                }
            }
        };

        if ( empty( $tempArr ) ) {
            return null; // There are none word with nearly the same "sounding" as $word
        } else {
            asort( $tempArr );
            $suggestWords = [];
            foreach ( $tempArr as $key => $value ) {
                $suggestWords[] = $key;
            }

            return $suggestWords;
        }
    }

}
