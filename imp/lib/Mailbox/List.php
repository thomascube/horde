<?php
/**
 * This class contains code related to generating and handling a mailbox
 * message list.
 *
 * Copyright 2002-2012 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.horde.org/licenses/gpl.
 *
 * @author   Michael Slusarz <slusarz@horde.org>
 * @category Horde
 * @license  http://www.horde.org/licenses/gpl GPL
 * @package  IMP
 */
class IMP_Mailbox_List implements ArrayAccess, Countable, Iterator, Serializable
{
    /* Serialized version. */
    const VERSION = 2;

    /**
     * Has the internal message list changed?
     *
     * @var boolean
     */
    public $changed = false;

    /**
     * The mailbox to work with.
     *
     * @var IMP_Mailbox
     */
    protected $_mailbox;

    /**
     * The list of additional variables to serialize.
     *
     * @var array
     */
    protected $_slist = array();

    /**
     * The array of sorted indices.
     *
     * @var array
     */
    protected $_sorted = null;

    /**
     * The mailboxes corresponding to the sorted indices list.
     * If empty, uses $_mailbox.
     *
     * @var array
     */
    protected $_sortedMbox = array();

    /**
     * The thread object representation(s) for the mailbox.
     *
     * @var array
     */
    protected $_thread = array();

    /**
     * The thread tree UI cached data.
     *
     * @var array
     */
    protected $_threadui = array();

    /**
     * Constructor.
     *
     * @param string $mbox  The mailbox to work with.
     */
    public function __construct($mbox)
    {
        $this->_mailbox = IMP_Mailbox::get($mbox);
    }

    /**
     * Build the array of message information.
     *
     * @param array $msgnum   An array of index numbers.
     * @param array $options  Additional options:
     *   - headers: (boolean) Return info on the non-envelope headers
     *              'Importance', 'List-Post', and 'X-Priority'.
     *              DEFAULT: false (only envelope headers returned)
     *   - preview: (mixed) Include preview information?  If empty, add no
     *              preview information. If 1, uses value from prefs.
     *              If 2, forces addition of preview info.
     *              DEFAULT: No preview information.
     *   - type: (boolean) Return info on the MIME Content-Type of the base
     *           message part ('Content-Type' header).
     *           DEFAULT: false
     *
     * @return array  An array with the following keys:
     *   - overview: (array) The overview information. Contains the following:
     *   - envelope: (Horde_Imap_Client_Data_Envelope) Envelope information
     *               returned from the IMAP server.
     *   - flags: (array) The list of IMAP flags returned from the server.
     *   - headers: (array) Horde_Mime_Headers objects containing header data
     *              if either $options['headers'] or $options['type'] are
     *              true.
     *   - idx: (integer) Array index of this message.
     *   - mailbox: (string) The mailbox containing the message.
     *   - preview: (string) If requested in $options['preview'], the preview
     *              text.
     *   - previewcut: (boolean) Has the preview text been cut?
     *   - size: (integer) The size of the message in bytes.
     *   - uid: (string) The unique ID of the message.
     *   - uids: (IMP_Indices) An indices object.
     */
    public function getMailboxArray($msgnum, $options = array())
    {
        $this->_buildMailbox();

        $headers = $overview = $to_process = $uids = array();

        /* Build the list of mailboxes and messages. */
        foreach ($msgnum as $i) {
            /* Make sure that the index is actually in the slice of messages
               we're looking at. If we're hiding deleted messages, for
               example, there may be gaps here. */
            if (isset($this->_sorted[$i - 1])) {
                $mboxname = $this->_mailbox->search
                    ? $this->_sortedMbox[$i - 1]
                    : strval($this->_mailbox);
                $to_process[$mboxname][$i] = $this->_sorted[$i - 1];
            }
        }

        $fetch_query = new Horde_Imap_Client_Fetch_Query();
        $fetch_query->envelope();
        $fetch_query->flags();
        $fetch_query->size();
        $fetch_query->uid();

        if (!empty($options['headers'])) {
            $headers = array_merge($headers, array(
                'importance',
                'list-post',
                'x-priority'
            ));
        }

        if (!empty($options['type'])) {
            $headers[] = 'content-type';
        }

        if (!empty($headers)) {
            $fetch_query->headers('imp', $headers, array(
                'cache' => true,
                'peek' => true
            ));
        }

        $imp_imap = $GLOBALS['injector']->getInstance('IMP_Factory_Imap')->create();

        if (empty($options['preview'])) {
            $cache = null;
            $options['preview'] = 0;
        } else {
            $cache = $imp_imap->getCache();
        }

        /* Retrieve information from each mailbox. */
        foreach ($to_process as $mbox => $ids) {
            try {
                $fetch_res = $imp_imap->fetch($mbox, $fetch_query, array(
                    'ids' => $imp_imap->getIdsOb($ids)
                ));

                if ($options['preview']) {
                    $preview_info = $tostore = array();
                    if ($cache) {
                        try {
                            $preview_info = $cache->get($mbox, $ids, array('IMPpreview', 'IMPpreviewc'));
                        } catch (IMP_Imap_Exception $e) {}
                    }
                }

                $mbox_ids = array();

                foreach ($ids as $k => $v) {
                    if (!isset($fetch_res[$v])) {
                        continue;
                    }

                    $f = $fetch_res[$v];
                    $v = array(
                        'envelope' => $f->getEnvelope(),
                        'flags' => $f->getFlags(),
                        'headers' => $f->getHeaders('imp', Horde_Imap_Client_Data_Fetch::HEADER_PARSE),
                        'idx' => $k,
                        'mailbox' => $mbox,
                        'size' => $f->getSize(),
                        'uid' => $f->getUid()
                    );

                    if (($options['preview'] === 2) ||
                        (($options['preview'] === 1) &&
                         (!$GLOBALS['prefs']->getValue('preview_show_unread') ||
                          !in_array(Horde_Imap_Client::FLAG_SEEN, $v['flags'])))) {
                        if (empty($preview_info[$v])) {
                            try {
                                $imp_contents = $GLOBALS['injector']->getInstance('IMP_Factory_Contents')->create(new IMP_Indices($mbox, $v));
                                $prev = $imp_contents->generatePreview();
                                $preview_info[$v] = array('IMPpreview' => $prev['text'], 'IMPpreviewc' => $prev['cut']);
                                if (!is_null($cache)) {
                                    $tostore[$v] = $preview_info[$v];
                                }
                            } catch (Exception $e) {
                                $preview_info[$v] = array('IMPpreview' => '', 'IMPpreviewc' => false);
                            }
                        }

                        $v['preview'] = $preview_info[$v]['IMPpreview'];
                        $v['previewcut'] = $preview_info[$v]['IMPpreviewc'];
                    }

                    $overview[] = $v;
                    $mbox_ids[] = $v['uid'];
                }

                $uids[$mbox] = $mbox_ids;

                if (!is_null($cache) && !empty($tostore)) {
                    $status = $imp_imap->status($mbox, Horde_Imap_Client::STATUS_UIDVALIDITY);
                    $cache->set($mbox, $tostore, $status['uidvalidity']);
                }
            } catch (IMP_Imap_Exception $e) {}
        }

        return array(
            'overview' => $overview,
            'uids' => new IMP_Indices($uids)
        );
    }

    /**
     * Returns true if the mailbox data has been built.
     *
     * @return boolean  True if the mailbox has been built.
     */
    public function isBuilt()
    {
        return !is_null($this->_sorted);
    }

    /**
     * Builds the sorted list of messages in the mailbox.
     */
    protected function _buildMailbox()
    {
        if ($this->isBuilt()) {
            return;
        }

        $this->changed = true;
        $this->_sorted = $this->_sortedMbox = array();

        $imp_imap = $GLOBALS['injector']->getInstance('IMP_Factory_Imap')->create();
        $imp_search = $query_ob = null;
        $sortpref = $this->_mailbox->getSort(true);
        $thread_sort = ($sortpref->sortby == Horde_Imap_Client::SORT_THREAD);

        if ($this->_mailbox->search) {
            $imp_search = $GLOBALS['injector']->getInstance('IMP_Search');
            $query_ob = $imp_search[strval($this->_mailbox)]->query;
        }

        if ($this->_mailbox->hideDeletedMsgs()) {
            $delete_query = new Horde_Imap_Client_Search_Query();
            $delete_query->flag(Horde_Imap_Client::FLAG_DELETED, false);

            if (is_null($query_ob))  {
                $query_ob = array(strval($this->_mailbox) => $delete_query);
            } else {
                foreach ($query_ob as $val) {
                    $val->andSearch($delete_query);
                }
            }
        }

        if (is_null($query_ob)) {
            $query_ob = array(strval($this->_mailbox) => null);
        }

        if ($thread_sort) {
            $this->_thread = $this->_threadui = array();
        }

        foreach ($query_ob as $mbox => $val) {
            if ($thread_sort) {
                $this->_getThread($mbox, $val ? array('search' => $val) : array());
                $sorted = $this->_thread[$mbox]->messageList()->ids;
                if ($sortpref->sortdir) {
                    $sorted = array_reverse($sorted);
                }
            } else {
                $res = $imp_imap->search($mbox, $val, array(
                    'sort' => array($sortpref->sortby)
                ));
                if ($sortpref->sortdir) {
                    $res['match']->reverse();
                }
                $sorted = $res['match']->ids;
            }

            $this->_sorted = array_merge($this->_sorted, $sorted);
            if ($imp_search && count($sorted)) {
                $this->_sortedMbox = array_merge($this->_sortedMbox, array_fill(0, count($sorted), $mbox));
            }
        }
    }

    /**
     * Get the list of unseen messages in the mailbox (IMAP UNSEEN flag, with
     * UNDELETED if we're hiding deleted messages).
     *
     * @param integer $results  A Horde_Imap_Client::SEARCH_RESULTS_* constant
     *                          that indicates the desired return type.
     * @param array $opts       Additional options:
     *   - sort: (array) List of sort criteria to use.
     *   - uids: (boolean) Return UIDs instead of sequence numbers (for
     *           $results queries that return message lists).
     *           DEFAULT: false
     *
     * @return mixed  Whatever is requested in $results.
     */
    public function unseenMessages($results, array $opts = array())
    {
        $count = ($results == Horde_Imap_Client::SEARCH_RESULTS_COUNT);

        if ($this->_mailbox->search || empty($this->_sorted)) {
            return ($count && $this->_mailbox->vinbox)
                ? count($this)
                : ($count ? 0 : array());
        }

        $criteria = new Horde_Imap_Client_Search_Query();
        $imp_imap = $GLOBALS['injector']->getInstance('IMP_Factory_Imap')->create();

        if ($this->_mailbox->hideDeletedMsgs()) {
            $criteria->flag(Horde_Imap_Client::FLAG_DELETED, false);
        } elseif ($count) {
            try {
                $status_res = $imp_imap->status($this->_mailbox, Horde_Imap_Client::STATUS_UNSEEN);
                return $status_res[Horde_Imap_Client::STATUS_UNSEEN];
            } catch (IMP_Imap_Exception $e) {
                return 0;
            }
        }

        $criteria->flag(Horde_Imap_Client::FLAG_SEEN, false);

        try {
            $res = $imp_imap->search($this->_mailbox, $criteria, array(
                'results' => array($results),
                'sequence' => empty($opts['uids']),
                'sort' => empty($opts['sort']) ? null : $opts['sort']
            ));
            return $count ? $res['count'] : $res;
        } catch (IMP_Imap_Exception $e) {
            return $count ? 0 : array();
        }
    }

    /**
     * Determines the sequence number of the first message to display, based
     * on the user's preferences.
     *
     * @param integer $total  The total number of messages in the mailbox.
     *
     * @return integer  The sequence number in the sorted mailbox.
     */
    public function mailboxStart($total)
    {
        if ($this->_mailbox->search) {
            return 1;
        }

        switch ($GLOBALS['prefs']->getValue('mailbox_start')) {
        case IMP::MAILBOX_START_FIRSTPAGE:
            return 1;

        case IMP::MAILBOX_START_LASTPAGE:
            return $total;

        case IMP::MAILBOX_START_FIRSTUNSEEN:
            if (!$this->_mailbox->access_sort) {
                return 1;
            }

            $sortpref = $this->_mailbox->getSort();

            /* Optimization: if sorting by sequence then first unseen
             * information is returned via a SELECT/EXAMINE call. */
            if ($sortpref->sortby == Horde_Imap_Client::SORT_SEQUENCE) {
                try {
                    $res = $GLOBALS['injector']->getInstance('IMP_Factory_Imap')->create()->status($this->_mailbox, Horde_Imap_Client::STATUS_FIRSTUNSEEN | Horde_Imap_Client::STATUS_MESSAGES);
                    if (!is_null($res['firstunseen'])) {
                        return $sortpref->sortdir
                            ? ($res['messages'] - $res['firstunseen'] + 1)
                            : $res['firstunseen'];
                    }
                } catch (IMP_Imap_Exception $e) {}

                return 1;
            }

            $unseen_msgs = $this->unseenMessages(Horde_Imap_Client::SEARCH_RESULTS_MIN, array(
                'sort' => array(Horde_Imap_Client::SORT_DATE),
                'uids' => true
            ));
            return empty($unseen_msgs['min'])
                ? 1
                : ($this->getArrayIndex($unseen_msgs['min']) + 1);

        case IMP::MAILBOX_START_LASTUNSEEN:
            if (!$this->_mailbox->access_sort) {
                return 1;
            }

            $unseen_msgs = $this->unseenMessages(Horde_Imap_Client::SEARCH_RESULTS_MAX, array(
                'sort' => array(Horde_Imap_Client::SORT_DATE),
                'uids' => true
            ));
            return empty($unseen_msgs['max'])
                ? 1
                : ($this->getArrayIndex($unseen_msgs['max']) + 1);
        }
    }

    /**
     * Rebuilds the mailbox.
     */
    public function rebuild()
    {
        $this->_sorted = null;
        $this->_buildMailbox();
    }

    /**
     * Returns the array index of the given message UID.
     *
     * @param integer $uid  The message UID.
     * @param string $mbox  The message mailbox (defaults to the current
     *                      mailbox).
     *
     * @return mixed  The array index of the location of the message UID in
     *                the current mailbox. Returns null if not found.
     */
    public function getArrayIndex($uid, $mbox = null)
    {
        $aindex = null;

        $this->_buildMailbox();

        if ($this->_mailbox->search) {
            if (is_null($mbox)) {
                $mbox = IMP::mailbox(true);
            }

            /* Need to compare both mbox name and message UID to obtain the
             * correct array index since there may be duplicate UIDs. */
            foreach (array_keys($this->_sorted, $uid) as $key) {
                if ($this->_sortedMbox[$key] == $mbox) {
                    return $key;
                }
            }
        } else {
            /* array_search() returns false on no result. We will set an
             * unsuccessful result to NULL. */
            if (($aindex = array_search($uid, $this->_sorted)) === false) {
                $aindex = null;
            }
        }

        return $aindex;
    }

    /**
     * Generate an IMP_Indices object out of the contents of this mailbox.
     *
     * @return IMP_Indices  An indices object.
     */
    public function getIndicesOb()
    {
        $this->_buildMailbox();
        $ob = new IMP_Indices();

        if ($this->_mailbox->search) {
            reset($this->_sorted);
            while (list($k, $v) = each($this->_sorted)) {
                $ob->add($this->_sortedMbox[$k], $v);
            }
        } else {
            $ob->add($this->_mailbox, $this->_sorted);
        }

        return $ob;
    }

    /**
     * Removes messages from the mailbox.
     *
     * @param mixed $indices  An IMP_Indices object or true to remove all
     *                        messages in the mailbox.
     *
     * @return boolean  True if the message was removed from the mailbox.
     */
    public function removeMsgs($indices)
    {
        if ($indices === true) {
            $this->rebuild();
            return false;
        }

        if (!count($indices)) {
            return false;
        }

        /* Remove the current entry and recalculate the range. */
        foreach ($indices as $ob) {
            foreach ($ob->uids as $uid) {
                $val = $this->getArrayIndex($uid, $ob->mbox);
                unset($this->_sorted[$val]);
                if ($this->_mailbox->search) {
                    unset($this->_sortedMbox[$val]);
                }
            }
        }

        $this->changed = true;
        $this->_sorted = array_values($this->_sorted);
        if ($this->_mailbox->search) {
            $this->_sortedMbox = array_values($this->_sortedMbox);
        }

        if (isset($this->_thread[strval($ob->mbox)])) {
            unset($this->_thread[strval($ob->mbox)], $this->_threadui[strval($ob->mbox)]);
        }

        return true;
    }

    /**
     * Returns the list of UIDs for an entire thread given one message in
     * that thread.
     *
     * @param integer $uid  The message UID.
     * @param string $mbox  The message mailbox (defaults to the current
     *                      mailbox).
     *
     * @return IMP_Indices  An indices object.
     */
    public function getFullThread($uid, $mbox = null)
    {
        if (is_null($mbox)) {
            $mbox = $this->_mailbox;
        }

        return new IMP_Indices($mbox, array_keys($this->_getThread($mbox)->getThread($uid)));
    }

    /**
     * Returns a thread object for a message.
     *
     * @param integer $offset  Sequence number of message.
     *
     * @return IMP_Mailbox_List_Thread  The thread object.
     */
    public function getThreadOb($offset)
    {
        $entry = $this[$offset];
        $mbox = strval($entry['m']);
        $uid = $entry['u'];

        if (!isset($this->_threadui[$mbox][$uid])) {
            $thread_level = array();
            $t_ob = $this->_getThread($mbox);

            foreach ($t_ob->getThread($uid) as $key => $val) {
                if (is_null($val->base) ||
                    ($val->last && ($val->base == $key))) {
                    $this->_threadui[$mbox][$key] = '';
                    continue;
                }

                if ($val->last) {
                    $join = IMP_Mailbox_List_Thread::JOINBOTTOM;
                } else {
                    $join = (!$val->level && ($val->base == $key))
                        ? IMP_Mailbox_List_Thread::JOINBOTTOM_DOWN
                        : IMP_Mailbox_List_Thread::JOIN;
                }

                $thread_level[$val->level] = $val->last;
                $line = '';

                for ($i = 0; $i < $val->level; ++$i) {
                    if (isset($thread_level[$i])) {
                        $line .= (isset($thread_level[$i]) && !$thread_level[$i])
                            ? IMP_Mailbox_List_Thread::LINE
                            : IMP_Mailbox_List_Thread::BLANK;
                    }
                }

                $this->_threadui[$mbox][$key] = $line . $join;
            }
        }

        return new IMP_Mailbox_List_Thread($this->_threadui[$mbox][$uid]);
    }

    /**
     * Returns the thread object for a mailbox.
     *
     * @param string $mbox  The mailbox.
     * @param array $extra  Extra options to pass to IMAP thread() command.
     *
     * @return Horde_Imap_Client_Data_Thread  Thread object.
     */
    protected function _getThread($mbox, array $extra = array())
    {
        if (!isset($this->_thread[strval($mbox)])) {
            try {
                $thread = $GLOBALS['injector']->getInstance('IMP_Factory_Imap')->create()->thread($mbox, array_merge($extra, array(
                    'criteria' => $GLOBALS['session']->get('imp', 'imap_thread')
                )));
            } catch (Horde_Imap_Client_Exception $e) {
                $thread = new Horde_Imap_Client_Data_Thread(array(), 'uid');
            }

            $this->_thread[strval($mbox)] = $thread;
        }

        return $this->_thread[strval($mbox)];
    }

    /* ArrayAccess methods. */

    /**
     * @param integer $offset  Sequence number of message.
     */
    public function offsetExists($offset)
    {
        return isset($this->_sorted[$offset - 1]);
    }

    /**
     * @param integer $offset  Sequence number of message.
     *
     * @return array  Two-element array:
     *   - m: (IMP_Mailbox) Mailbox of message.
     *   - u: (string) UID of message.
     */
    public function offsetGet($offset)
    {
        if (!isset($this->_sorted[$offset - 1])) {
            return null;
        }

        $ret = array(
            'm' => (empty($this->_sortedMbox) ? $this->_mailbox : IMP_Mailbox::get($this->_sortedMbox[$offset - 1])),
            'u' => $this->_sorted[$offset - 1]
        );

        return $ret;
    }

    /**
     * @throws BadMethodCallException
     */
    public function offsetSet($offset, $value)
    {
        throw new BadMethodCallException('Not supported');
    }

    /**
     * @throws BadMethodCallException
     */
    public function offsetUnset($offset)
    {
        throw new BadMethodCallException('Not supported');
    }

    /* Countable methods. */

    /**
     * Returns the current message count of the mailbox.
     *
     * @return integer  The mailbox message count.
     */
    public function count()
    {
        $this->_buildMailbox();
        return count($this->_sorted);
    }

    /* Iterator methods. */

    /**
     * @return array  Two-element array:
     *   - m: (IMP_Mailbox) Mailbox of message.
     *   - u: (string) UID of message.
     */
    public function current()
    {
        return $this[key($this->_sorted) + 1];
    }

    /**
     * @return integer  Sequence number of message.
     */
    public function key()
    {
        return (key($this->_sorted) + 1);
    }

    /**
     */
    public function next()
    {
        next($this->_sorted);
    }

    /**
     */
    public function rewind()
    {
        reset($this->_sorted);
    }

    /**
     */
    public function valid()
    {
        return (key($this->_sorted) !== null);
    }

    /* Serializable methods. */

    /**
     * Serialization.
     *
     * @return string  Serialized data.
     */
    public function serialize()
    {
        $data = array(
            'm' => $this->_mailbox,
            'v' => self::VERSION
        );

        if (!is_null($this->_sorted)) {
            $data['so'] = $this->_sorted;
            if (!empty($this->_sortedMbox)) {
                $data['som'] = $this->_sortedMbox;
            }
        }

        foreach ($this->_slist as $val) {
            $data[$val] = $this->$val;
        }

        return serialize($data);
    }

    /**
     * Unserialization.
     *
     * @param string $data  Serialized data.
     *
     * @throws Exception
     */
    public function unserialize($data)
    {
        $data = @unserialize($data);
        if (!is_array($data) ||
            !isset($data['v']) ||
            ($data['v'] != self::VERSION)) {
            throw new Exception('Cache version change');
        }

        $this->_mailbox = $data['m'];

        if (isset($data['so'])) {
            $this->_sorted = $data['so'];
            if (isset($data['som'])) {
                $this->_sortedMbox = $data['som'];
            }
        }

        foreach ($this->_slist as $val) {
            $this->$val = $data[$val];
        }
    }

}
