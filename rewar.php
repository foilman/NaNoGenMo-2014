<?php
$file="http://www.gutenberg.org/files/36/36-h/36-h.htm";

function Fail($m)
{
	die($m."\n");
}

function P($t)
{
	echo wordwrap($t)."\n";
}

// StartEnd
// Is this a start/end block, i.e. not part of the text?
function StartEnd($v)
{
	return (preg_match("/^\*\*\* (?:START|END)/m",$v));
}

// TrimWord
function TrimWord($word)
{
	return trim($word,"-,'.!?();\"");
}

// AnalyseDoc
// Analyse each node in the document and populate blocks
function AnalyseDoc(&$body,&$blocks,&$block_num,&$wordlocs,&$nbmap)
{
	$start_end_nodes=array();
	$nn=0;
	foreach ($body->childNodes as $node)
	{
		// Note start/end blocks for removal later
		if (StartEnd($node->nodeValue))
		{
			$start_end_nodes[]=$node;
			continue;
		}

		$bn=AnalyseNode($node->nodeName,$node->nodeValue,$blocks,$block_num,$wordlocs);
		
		// Keep map from original node number to block number
		$nbmap[$nn]=$bn;
		$nn++;
	}
	
	// Remove start/end nodes
	foreach ($start_end_nodes as $node)
	{
		$body->removeChild($node);
	}
}

// AnalyseNode
// n: type of node
// v: value of node
function AnalyseNode($n,$v,&$blocks,&$block_num,&$wordlocs)
{
	if (trim($v)=="") return -1;
	
	// Attempt to split block into sentences
	$s=preg_split('/([.?!]\s*|,?\s*["()]\s*)/',$v,-1,PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
	
	for ($snum=0; $snum<count($s); $snum++)
	{
		$sentence=$s[$snum];
		
		// Replace any occurrences of strings of dashes with the same but with spaces around
		$sentence=preg_replace("/(--+)/"," $1 ",$sentence);

		// If there are no actual words in the sentence, just put it as a single
		// entry in the sentence array
		if (!preg_match('/\w/',$sentence))
		{
			$words=array($sentence);
		}
		else
		{
			// Split the sentence into words around whitespace
			if ($n=="pre") $regexp='/(\h+)/'; else $regexp='/(\s+)/';
			$words=preg_split($regexp,$sentence,-1,PREG_SPLIT_NO_EMPTY);
			
			if (count($words)==0) $words=array($sentence);
		}
		
		// Only analyse sentences that are more than 2 words long
		if (count($words)>2)
		{
			for ($wordnum=1; $wordnum<count($words)-1; $wordnum++)
			{
				$word=$words[$wordnum];
			
				// Remove any punctuation at the start or end of the word
				$word=TrimWord($word);
				if ($word=="") continue;
				
				// Record the location of this word within the document, the block, and the sentence
				if (!isset($wordlocs[$word])) $wordlocs[$word]=array();
				$wordlocs[$word][]=array($block_num,$snum,$wordnum);
			}
		}
		
		// Replace the sentence with an array of the words
		$s[$snum]=$words;
	}

	// Save this block of sentences
	$blocks[$block_num]=$s;
	$block_num++;
	return $block_num-1;
}

// DoSwap
// Find the sentences with this word in, split them around
// the word, and swap the pieces
function DoSwap($word,$num,&$blocks,&$wordlocs)
{
	// Generate a list of the location keys (eg 0,1,2,3,4)
	$loclist=array_keys($wordlocs[$word]);
	
	// Randomise the order (eg 3,1,0,4,2)
	shuffle($loclist);
	
	$swapped=array();
	for ($locidx=0; $locidx<$num; $locidx++)
	{
		$li1=$locidx;
		$li2=$loclist[$locidx];
		
		if ($li1==$li2) continue; // nothing being swapped
	
		// First part of sentence comes from $li1, second from $li2
		$loc1=$wordlocs[$word][$li1];
		$loc2=$wordlocs[$word][$li2];

		if (isset($swapped[$li2]) && $swapped[$li2]==$li1) continue; // already swapped these two
		$swapped[$li1]=$li2;
		
		$block_num1=$loc1[0];
		$snum1=$loc1[1];
		$wordnum1=$loc1[2];
		
		$block_num2=$loc2[0];
		$snum2=$loc2[1];
		$wordnum2=$loc2[2];
		
		if ($block_num1==$block_num2 && $snum1==$snum2) continue; // no swapping within same sentence
		
		$s1=$blocks[$block_num1][$snum1];
		$s2=$blocks[$block_num2][$snum2];
		
		// New s1 is everything up to, but not including wordnum1 from s1 + everything from wordnum2 from s2
		$ns1=array_merge(array_slice($s1,0,$wordnum1),array_slice($s2,$wordnum2));
		// Opposite for s2
		$ns2=array_merge(array_slice($s2,0,$wordnum2),array_slice($s1,$wordnum1));
		
		// If there are any indexed words in the second half of each sentence,
		// they need re-indexing
		
		// Second half of s2, which is going to s1
		for ($wnum2=$wordnum2; $wnum2<count($s2); $wnum2++)
		{
			$wordw=$s2[$wnum2];
			$new_wordnum=$wnum2-$wordnum2+$wordnum1;
			// Move to a negative block, to avoid the situation where a word is moved
			// to a sentence which already has the same word in the same location
			// (which always happens for the word being handled, but may happen to others)
			MoveWord($wordw,$wordlocs,$block_num2,$snum2,$wnum2,-$block_num1,$snum1,$new_wordnum);
		}
		// Second half of s1, which is going to s2
		for ($wnum1=$wordnum1; $wnum1<count($s1); $wnum1++)
		{
			$wordw=$s1[$wnum1];
			$new_wordnum=$wnum1-$wordnum1+$wordnum2;
			MoveWord($wordw,$wordlocs,$block_num1,$snum1,$wnum1,$block_num2,$snum2,$new_wordnum);
		}
		// Fix up words from second half of s2 with negative block numbers
		for ($wnum2=$wordnum2; $wnum2<count($s2); $wnum2++)
		{
			$wordw=$s2[$wnum2];
			FixNegBlock($wordw,$wordlocs);
		}
		
		// Store the updates back
		$blocks[$block_num1][$snum1]=$ns1;
		$blocks[$block_num2][$snum2]=$ns2;
	}
}

// MoveWord
// Update the position of a word from one block/sentence/word position to another
function MoveWord($word,&$wordlocs,$blockfrom,$sfrom,$wfrom,$blockto,$sto,$wto)
{
	$word=TrimWord($word);
	if (!isset($wordlocs[$word])) return;
	
	for ($loc=0; $loc<count($wordlocs[$word]); $loc++)
	{
		$loci=$wordlocs[$word][$loc];
		if ($loci[0]==$blockfrom // same block
			&& $loci[1]==$sfrom  // same sentence
			&& $loci[2]==$wfrom) // same word
		{
			// Store new location
			$wordlocs[$word][$loc]=array($blockto,$sto,$wto);
		}
	}
}

// FixNegBlock
// Change a negative block number back to a positive for a given word
function FixNegBlock($word,&$wordlocs)
{
	$word=TrimWord($word);
	if (!isset($wordlocs[$word])) return;
	for ($loc=0; $loc<count($wordlocs[$word]); $loc++)
	{
		$loci=$wordlocs[$word][$loc];
		if ($loci[0]<0)
		{
			// Fix block number for this word/location
			$wordlocs[$word][$loc][0]=-$loci[0];
		}
	}
}

// RebuildDoc
function RebuildDoc(&$body,&$blocks,&$nbmap)
{
	$nn=0;
	foreach ($body->childNodes as $node)
	{
		$block_num=$nbmap[$nn];
		if ($block_num>-1)
		{
			// Extract list of sentences for this block
			$slist=$blocks[$block_num];
			$sents=array();
			foreach ($slist as $s)
			{
				// Join words back into a sentence
				$s=join(" ",$s);
				
				// In a <pre> section, maintain line breaks; otherwise
				// replace them with spaces
				if ($node->nodeName!="pre") $s=str_replace("\n"," ",$s);
				
				$sents[]=$s;
			}
			$node->nodeValue=join("",$sents);
		}
		$nn++;
	}
}

$blocks=array();
$block_num=0;
$wordlocs=array();
$nbmap=array();

$wotw=new DOMDocument();
@$wotw->loadHTMLFile($file) || Fail("Couldn't load file ".$file);

// Update the title
$title=$wotw->getElementsByTagName("title")->item(0);
$title->nodeValue="The Remix Of The Worlds";
$ts=$wotw->getElementsByTagName("h1");
$ts->item(0)->nodeValue="The Remix Of The Worlds";
$ts->item(1)->nodeValue="by H. G. Wells [1898] and rewar.php [2014]";

$body=$wotw->getElementsByTagName("body")->item(0);

// Analysis pass
AnalyseDoc($body,$blocks,$block_num,$wordlocs,$nbmap);

// Repeatable random seed
$seed=1;
srand($seed);

// Arrange the words in order
ksort($wordlocs);

$numswaps=-1; // how many swaps? -1 for all of them
foreach ($wordlocs as $word=>$locs)
{
	// Only include words that occur between 2 and 20 times in the text
	$num=count($locs);
	if ($num<2 || $num>20) continue;

	// Show progress
	P($word." (".$num.")");
	DoSwap($word,$num,$blocks,$wordlocs);

	// Check if we've done enough swaps for now
	$numswaps--;
	if ($numswaps==0) break;
}

RebuildDoc($body,$blocks,$nbmap);

file_put_contents("wwout".$seed.".html",$wotw->saveHTML());

?>
